---
paths:
  - 'app-modules/*/src/Data/**'
---

# Data

## Never name a static Data method from* unless it is a magic creator
laravel-data treats ANY public static `from*` method on a Data class as a magic creation method and routes casts/`from()` through it by best-match signature. A helper like `fromEventPayload(array $payload)` silently hijacks the Eloquent JSON cast (which stores camelCase keys) and blows up with "Undefined array key". Name such helpers `of*`/`make*` instead — see BillingPartyData::ofEventPayload.
