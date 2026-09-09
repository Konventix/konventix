# syntax=docker/dockerfile:1
# The production image (ADR-0049 §1): one image, every role — web (Octane/
# FrankenPHP), horizon, scheduler, pulse, reverb, migrate, install — selected
# by command override. The Vite build runs with VITE_REVERB_* stripped from
# its throwaway .env on purpose: the bundle then derives the websocket
# endpoint from the page (ADR-0049 §5).

ARG PHP_IMAGE=dunglas/frankenphp:1-php8.5-bookworm

# Node for the Vite build, taken from the official image and pinned to a
# digest (#255). It used to be `curl -fsSL https://deb.nodesource.com/... |
# bash -`: an unpinned, unverified remote script running as root at build
# time, in the `toolchain` stage — which .gitlab-ci.yml publishes as
# `ci-toolchain` and runs every verify, analyse and test job inside. A bad day
# at nodesource would have been a compromise of the CI execution environment,
# not merely of one build. Nothing is fetched over the network here now, and
# the bytes are the ones this digest names.
#
# Note for the next bump: change the tag and the digest together, and check
# that the Debian release still matches the PHP base image's (bookworm) — the
# node binary is copied into it and links against its glibc.
ARG NODE_IMAGE=node:22-bookworm-slim@sha256:83f487e0a63425e5b4d146fb5e5be574bcbe1b7b843d3ebafdd95eaf7767a7e5

########## base: PHP + extensions shared by build and runtime ##########
FROM ${PHP_IMAGE} AS base

RUN install-php-extensions \
    pdo_pgsql pgsql redis intl bcmath gmp gd zip pcntl sockets opcache

WORKDIR /var/www/html

########## node: the Vite build's interpreter, copied not installed ##########
FROM ${NODE_IMAGE} AS node

########## toolchain: composer + Node on top of base ##########
# Also the CI image: .gitlab-ci.yml builds this stage and publishes it, so the
# pipeline lints, analyses and tests on the same PHP build, extension set and
# Node major that produce the release artifact — not on a lookalike. Nothing
# here reaches `runtime`, which starts again from `base` and copies only
# /var/www/html out of `build`.
FROM base AS toolchain

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# git and unzip are composer's: the image build never needs them (--prefer-dist
# over the zip extension), the pipeline's dev-dependency install does.
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl git unzip \
    && rm -rf /var/lib/apt/lists/*

# Node 22 for the Vite build only — the runtime stage never sees it, because it
# starts again from `base`. The interpreter plus npm's own module tree is the
# whole of what a build needs; the npm and npx entry points are symlinks in the
# source image and are recreated rather than copied.
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -sf ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -sf ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && node --version && npm --version

########## manifests: only the files the installers resolve from ##########
#
# `composer.json` declares one path repository, `app-modules/*` with
# `symlink: true`, so `composer install` has to read all nineteen modules'
# `composer.json` — and nothing else of them. Copying the whole tree first is
# what tied the install to every source change (#141): one line in a Vue file
# invalidated `COPY . .` and dragged `composer install` and `npm ci` with it.
#
# This stage reduces the tree to those nineteen manifests. BuildKit keys the
# next stage's `COPY --from` on the *content* of what it copies, so a change
# anywhere else in a module leaves the install layers cached, while adding a
# module or moving a dependency still busts them, which is exactly right.
#
# busybox `cp` has no `--parents`, hence the loop.
FROM alpine:3.22 AS manifests

COPY app-modules /src/app-modules
RUN set -eu; \
    for dir in /src/app-modules/*/; do \
      [ -f "$dir/composer.json" ] || continue; \
      target="/manifests/app-modules/$(basename "$dir")"; \
      mkdir -p "$target"; \
      cp "$dir/composer.json" "$target/composer.json"; \
    done; \
    ls /manifests/app-modules | wc -l

########## build: composer, wayfinder, vite ##########
FROM toolchain AS build

# Every `php artisan` in this stage boots the framework — key:generate,
# storage:link, and wayfinder:generate, which the Vite plugin shells out to.
# `.env.example`, copied in below, points DB_HOST at `pgsql` and REDIS_HOST at
# `redis` and puts the cache, session and queue stores on redis. Inside a
# build there are no such hosts, so every one of those boots stalls on name
# resolution before giving up.
#
# That is the largest single cost in the image build, and the build says so
# itself: `[PLUGIN_TIMINGS] … @laravel/vite-plugin-wayfinder buildStart (97%,
# 214.5s, 1 call)`. `build:ssr` runs Vite twice, so `npm run build:ssr` took
# 449s of a 776s build (pipeline 1689). Timed directly on the same command in
# the same image: 116s with the compose hostnames, 1s with the five values
# below.
#
# It is the fix .gitlab-ci.yml already applies to its service-less jobs
# (`.offline-services`, `.ai/rules/ci.md`): a loopback address refuses at once
# instead of timing out, and the stores never leave memory. Nothing in this
# stage runs application code that needs a database or a cache — composer,
# wayfinder and Vite read files.
#
# None of it reaches the artifact. The runtime stage is a separate `FROM`;
# `.env` is deleted at the end of this stage; the only thing carried forward,
# `/opt/bootstrap-cache`, holds package discovery, which does not vary with
# these; and Vite bakes only `VITE_*`. Laravel's Dotenv is immutable, so a real
# environment variable wins over the `.env` copied in below — which is what
# makes setting them here enough.
ENV DB_HOST=127.0.0.1 \
    REDIS_HOST=127.0.0.1 \
    CACHE_STORE=array \
    SESSION_DRIVER=array \
    QUEUE_CONNECTION=sync

# Dependencies before source, so that editing a Vue file does not reinstall
# them (#141). Both installers resolve from lockfiles alone: composer from the
# root pair plus the module manifests above, npm from its own pair — package.json
# declares no install or prepare script, so `npm ci` needs nothing else.
#
# `--no-scripts --no-autoloader` because both would need the source that is not
# here yet: composer's `post-autoload-dump` runs `package:discover`, which boots
# the application. The autoloader is generated below, once the tree is complete,
# and that dump is what finally runs the discovery.
COPY composer.json composer.lock ./
COPY --from=manifests /manifests/app-modules ./app-modules
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist \
      --no-interaction --no-progress

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

# The classmap over the finished tree. Still `--no-scripts`: the discovery that
# `post-autoload-dump` would run boots the application and reads a `.env` that
# does not exist yet — and that `.env` cannot be made first, because
# `key:generate` is itself an artisan call and needs this autoloader. So the
# three steps that used to be one `composer install` are spelled out in the
# order their dependencies actually allow.
RUN composer dump-autoload --no-dev --optimize --no-scripts --no-interaction

# A throwaway environment: the artisan calls below boot the application, which
# needs a key. Nothing of this .env reaches the image.
#
# .env.example carries the dev websocket endpoint (localhost:8080). Vite bakes
# any VITE_* it can see into the bundle, so strip them: with none present the
# client falls back to deriving host, port and scheme from the page it was
# served by (ADR-0049 §5), which is what every deployment needs.
RUN cp .env.example .env \
 && sed -i '/^VITE_REVERB_/d' .env \
 && php artisan key:generate

# What `post-autoload-dump` would have done, now that both the tree and the
# environment are in place: this is what writes the `bootstrap/cache` the
# runtime stage carries as `/opt/bootstrap-cache`.
RUN php artisan package:discover

# octane:start copies this stub into public/ at startup unless it already
# exists — a copy that can never succeed on the chart's read-only root
# filesystem, so it ships in the image (first deploy test, finding 4).
RUN cp vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php \
      public/frankenphp-worker.php

# build + SSR bundle; wayfinder + the Bunny fonts plugin run inside (network
# needed — an accepted non-hermetic step, ADR-0049 consequences).
RUN npm run build:ssr

RUN php artisan storage:link \
    && rm .env \
    && rm -rf node_modules \
    && cp -r bootstrap/cache /opt/bootstrap-cache
# The tree's file modes come from whoever checked the sources out: the
# GitLab runner does so with a permissive umask and hands the build a tree
# whose every file is group- and world-writable, while a developer's
# checkout is not. The runtime stage copies these modes verbatim and only
# ever adds read bits, so its "root-owned, read-only for the runtime user"
# contract (#257) held on a laptop and failed in CI. Stripping the write bits
# here, still inside the build stage, costs nothing in the runtime image: the
# COPY --from that follows carries the corrected modes and adds no layer.
RUN find /var/www/html /opt/bootstrap-cache ! -type l \( -perm /g+w -o -perm /o+w \) -exec chmod go-w {} +

########## runtime ##########
FROM base AS runtime

# pg_dump for spatie backups (ADR-0049 §8), same major as the server (17).
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl gnupg \
    && install -d /usr/share/postgresql-common/pgdg \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
        -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc \
    && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" \
        > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client-17 \
    && apt-get purge -y gnupg \
    && rm -rf /var/lib/apt/lists/*

# Caddy/FrankenPHP write autosave and instance state under XDG paths — point
# them at tmp so the image stays read-only-friendly (ADR-0049 §1).
ENV XDG_CONFIG_HOME=/tmp/xdg-config \
    XDG_DATA_HOME=/tmp/xdg-data \
    OCTANE_SERVER=frankenphp

ARG APP_VERSION=dev
ARG SENTRY_RELEASE=
ENV APP_VERSION=${APP_VERSION} \
    SENTRY_RELEASE=${SENTRY_RELEASE}

# The upstream frankenphp base grants cap_net_bind_service via setcap; under
# the chart's allowPrivilegeEscalation=false (no_new_privs), exec of a
# binary carrying file capabilities is EPERM before a single line of ours
# runs (first deploy test, finding 5). We bind 8000 — the capability is
# dead weight, and B1 promised "no setcap, no port 80" all along.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcap2-bin \
    && setcap -r /usr/local/bin/frankenphp \
    && apt-get purge -y libcap2-bin && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

# The base image activates no php.ini at all (#254), so without this the
# runtime runs on PHP's compiled-in defaults: errors into the response body,
# nothing logged, the version in every header, 128M per worker and opcache
# revalidating immutable code. Runtime stage only — `toolchain` is the
# published CI image and keeps taking .gitlab-ci.yml's own 99-ci.ini.
COPY docker/php.ini /usr/local/etc/php/conf.d/99-production.ini

RUN useradd --create-home --uid 1000 konventix

# Root owns the artifact; the runtime user owns only what has to be written
# (#257). Before this, every PHP file, `docker/entrypoint.sh` — the ENTRYPOINT —
# and the `/opt/bootstrap-cache` seed the entrypoint copies from belonged to the
# UID the server runs as: a write primitive anywhere in the application tree was
# code execution, and on a consumer without a read-only root filesystem
# (compose.prod-local.yaml, the CI smoke step) it was persistence past the next
# boot as well. The chart's `readOnlyRootFilesystem` is a mitigation in another
# repository; this one lives with the artifact.
#
# What this does *not* close, stated plainly: `public/storage` is a symlink into
# `storage/app/public`, which stays writable by design, and Octane's Caddyfile
# stub serves `public/` with `php_server` + `resolve_root_symlink` — its own
# comment says the option is there "for the `public/storage/` directory". So a
# `.php` written into the media tree (the `local` media disk with
# `public_direct`, which `ci/values-local-disk.yaml` deploys) is still executed
# by the web server. Closing that needs `docker/Caddyfile` — #257's still-open
# criterion — with a matcher that refuses PHP under `/storage`
# (`@storagephp path_regexp ^/storage/.*\.php$` → `respond 404`) placed before
# `php_server`. Until that file exists, the honest claim for this block is "the
# application tree is no longer writable", not "a write is no longer execution".
# `storage:link` must stay in the build stage either way: the local-disk
# deployment mode needs it.
#
# `storage` and `bootstrap/cache` are the whole of what is written at runtime:
# the entrypoint's `artisan config:cache` / `route:cache` / `view:cache` /
# `event:cache` write into them, and both are mounts in the deployment anyway.
# Nothing else is: `public/frankenphp-worker.php` already ships (Octane's
# `ensureFrankenPhpWorkerIsInstalled()` only copies its stub when the file is
# absent), Octane reads the Caddyfile out of `vendor/`, and Caddy's own state
# goes to the XDG paths pointed at /tmp above.
COPY --from=build --chown=root:root /var/www/html /var/www/html
COPY --from=build --chown=root:root /opt/bootstrap-cache /opt/bootstrap-cache

#
# The base image leaves the WORKDIR itself at `www-data:www-data 1777`, which
# would let the runtime user drop new files beside the application even with
# every file in it root-owned. Nothing serves from there (Caddy's root is
# `public/`, itself root-owned) but it costs one chmod to close.
#
# The `find` is the price of dropping `--chown`: with the tree owned by the
# user that copied it, a source directory's own mode never mattered; root-owned,
# it does. A developer's Stripe CLI leaves `config/stripe` at 0700, and the
# image built fine and then died at boot on
# `RecursiveDirectoryIterator(/var/www/html/config/stripe): Permission denied`.
# Normalising the few offending entries costs a metadata-only layer; a blanket
# `chmod -R` would copy every file up and roughly double the image.
RUN chmod +x docker/entrypoint.sh \
    && chmod 755 /var/www/html \
    && find /var/www/html \( \( -type d ! -perm -o+rx \) -o \( -type f ! -perm -o+r \) \) -exec chmod o+rX {} + \
    && chown -R konventix:konventix storage bootstrap/cache

USER konventix

EXPOSE 8000

# The base image ships `curl -f http://localhost:2019/metrics` — Caddy's admin
# API, a listener this image should not be exposing at all (#257) and one that
# says nothing about whether the application answers. `/up` is what the chart's
# web liveness probe asks for, so a `docker run` or Compose consumer now gets
# the same answer Kubernetes does; Kubernetes itself ignores HEALTHCHECK and
# keeps using the probe. The roles that serve no HTTP (horizon, scheduler,
# pulse, migrate) report unhealthy under Compose — as they already did, since
# nothing listened on 2019 there either.
HEALTHCHECK --interval=30s --timeout=3s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8000/up || exit 1

ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000"]
