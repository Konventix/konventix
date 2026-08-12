---
paths:
  - phpstan.neon
  - compose.yaml
---

# General

## PHPStan needs --memory-limit=1G; tests/ is out of scope
Run it as `composer types:check` — plain `phpstan analyse` dies at PHP's default 128M with a misleading "Unable to load dynamic library phpstan_turbo" warning. The turbo extension is a red herring; the memory limit is the actual cause.

`tests/` is deliberately not in `paths`. Pest's higher-order `TestCall` chain is invisible to PHPStan without `pestphp/pest-plugin-phpstan`, so scanning tests yields only false positives. Add the plugin first if you want the coverage.

## Dev stack mirrors production roles: one image, one container per role
compose.yaml runs each deployment role (web, queue, scheduler, reverb) as a separate service from the same Sail image via command override — mirroring ADR-0002/ADR-0025 production topology. Never mount docker.sock or spawn containers from the application; that is root-equivalent host access. Reverb is ONE shared server (browser: localhost:${FORWARD_REVERB_PORT}; backend: http://reverb:8080); brands become app entries in config/reverb.php, not extra processes. Host ports 8080–8083 clash with other local stacks on this machine — FORWARD_REVERB_PORT dodges that.
