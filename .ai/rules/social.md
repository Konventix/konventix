---
paths:
  - 'app-modules/*/src/Social/**'
---

# Social

## Socialite drivers cache the request — re-set it, and flush the factory
Socialite binds its Factory as a container singleton and Manager caches each driver; a driver holds the Request it was first built for. Under Octane the second social login in a worker verifies the FIRST request's parameters — for Steam that means validating the wrong assertion. Always call $driver->setRequest(request()) before use, and keep Laravel\Socialite\Contracts\Factory::class in config/octane.php 'flush'. Same reason redirect URLs are built per request via route(), never from services.*.redirect: a static value sends every brand's users to one host and breaks brand-scoped sessions.
