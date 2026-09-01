# syntax=docker/dockerfile:1
# The production image (ADR-0049 §1): one image, every role — web (Octane/
# FrankenPHP), horizon, scheduler, pulse, reverb, migrate, install — selected
# by command override. The Vite build runs with VITE_REVERB_* stripped from
# its throwaway .env on purpose: the bundle then derives the websocket
# endpoint from the page (ADR-0049 §5).

ARG PHP_IMAGE=dunglas/frankenphp:1-php8.5-bookworm

########## base: PHP + extensions shared by build and runtime ##########
FROM ${PHP_IMAGE} AS base

RUN install-php-extensions \
    pdo_pgsql pgsql redis intl bcmath gmp gd zip pcntl sockets opcache

WORKDIR /var/www/html

########## build: composer, wayfinder, vite ##########
FROM base AS build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node 22 for the Vite build only — the runtime stage never sees it.
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl gnupg \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY . .

# --no-dev: Telescope and all dev tooling are absent from the artifact.
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# octane:start copies this stub into public/ at startup unless it already
# exists — a copy that can never succeed on the chart's read-only root
# filesystem, so it ships in the image (first deploy test, finding 4).
RUN cp vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php \
      public/frankenphp-worker.php

# A throwaway environment: wayfinder:generate (run by the Vite plugin) boots
# the application, which needs a key. Nothing of this .env reaches the image.
RUN cp .env.example .env && php artisan key:generate

# .env.example carries the dev websocket endpoint (localhost:8080). Vite bakes
# any VITE_* it can see into the bundle, so strip them: with none present the
# client falls back to deriving host, port and scheme from the page it was
# served by (ADR-0049 §5), which is what every deployment needs.
RUN sed -i '/^VITE_REVERB_/d' .env

RUN npm ci

# build + SSR bundle; wayfinder + the Bunny fonts plugin run inside (network
# needed — an accepted non-hermetic step, ADR-0049 consequences).
RUN npm run build:ssr

RUN php artisan storage:link \
    && rm .env \
    && rm -rf node_modules \
    && cp -r bootstrap/cache /opt/bootstrap-cache

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

RUN useradd --create-home --uid 1000 konventix

COPY --from=build --chown=konventix:konventix /var/www/html /var/www/html
COPY --from=build --chown=konventix:konventix /opt/bootstrap-cache /opt/bootstrap-cache

RUN chmod +x docker/entrypoint.sh

USER konventix

EXPOSE 8000

ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000"]
