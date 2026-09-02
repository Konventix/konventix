---
paths:
  - '.gitlab-ci.yml'
  - '.github/workflows/**'
---

# CI

## The security templates ignore merge requests unless AST_ENABLE_MR_PIPELINES is set
Every scanner's rules in `Jobs/Secret-Detection`, `Jobs/SAST`, `Jobs/Dependency-Scanning` and `Jobs/Container-Scanning` read `$AST_ENABLE_MR_PIPELINES == "true" && $CI_PIPELINE_SOURCE == "merge_request_event"`, and their remaining branches only match `$CI_COMMIT_BRANCH`. Since `workflow:` here creates no branch pipeline outside main, leaving the variable unset means the scans never see a merge request — they run on main, after the merge they exist to block, and the pipeline still looks green all the way through. Set it once at the top level, not per job.

Two related shapes on this instance (GitLab 19.3-ee, Ultimate): the new `dependency_scanning` job is `rules: [when: never]`, so `gemnasium-dependency_scanning` is the job that actually runs and the one to name in `needs:`; and `semgrep-sast` steps aside for the languages `gitlab-advanced-sast` covers once `GITLAB_ADVANCED_SAST_ENABLED` is on, so on a PHP/Vue commit only the advanced analyzer appears. Anything downstream must use `needs: [{job: …, optional: true}]` — which analyzers a commit produces depends on the files it touched, and a scanner that did not run is not a finding.

## The analyzers exit 0 with findings; scan:gate is what fails the pipeline
`allow_failure` on a scanner only covers the analyzer crashing. A report full of vulnerabilities is a successful job. `scan:gate` reads `gl-secret-detection-report.json`, `gl-sast-report.json` and `gl-dependency-scanning-report.json` and is the job that goes red: any secret at all, High and above for code and dependencies. Container scanning is deliberately outside the gate — the base image's OS packages produce CVEs on their own schedule and must not be able to stop every merge in the repository.

## dind needs plain TCP: these runners do not share the TLS cert volume
`DOCKER_TLS_CERTDIR: "/certs"` is the documented setup and it dies here on `Failed to initialize: unable to resolve docker endpoint: open /certs/client/ca.pem: no such file or directory`, before the job's first line — the runner config would have to mount `/certs/client` into the job container and does not. Use `DOCKER_HOST: tcp://docker:2375` with `DOCKER_TLS_CERTDIR: ""`. The daemon is only reachable on the job's own network. The same jobs also need `docker buildx create --driver docker-container`: the default `docker` driver cannot export `--cache-to type=registry`, and a retry on the same runner must fall through to `docker buildx use`.

## The CI image is the Dockerfile's `toolchain` stage, published from main only
Jobs run on `$CI_REGISTRY_IMAGE/ci-toolchain:$TOOLCHAIN_TAG`, built with `--target toolchain` so the pipeline's PHP build, extension set and Node major are the ones that produce the release artifact. A merge request may create that tag when it is missing but never republishes it — one branch's Dockerfile experiment must not change the image every other pipeline runs on. Its own Dockerfile change is still proven, because `build:image` builds the whole file including that stage. If you change `toolchain` in a way every job must see at once, bump `TOOLCHAIN_TAG` in the same commit; a Dockerfile change alone republishes the tag only when it reaches main.

## Every job needs wayfinder:generate before it touches the frontend
`resources/js/{routes,actions,wayfinder}` are gitignored, so a fresh clone has none of them and eslint's import resolver, `vue-tsc` and vitest all fail on unresolved imports rather than on anything real. `php artisan wayfinder:generate --with-form` (the `--with-form` matches `formVariants: true` in vite.config.ts) runs in the shared `before_script`, which is also why every job installs both composer and npm dependencies even when it only runs one of them.
