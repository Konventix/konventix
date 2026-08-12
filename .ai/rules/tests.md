---
paths:
  - 'app-modules/*/tests/**'
---

# Tests

## Never call Event::fake() without arguments — it breaks BelongsToBrand
Blanket Event::fake() also swallows eloquent.* model events, so the BelongsToBrand creating hook never stamps brand_id and inserts die on the not-null constraint (ULIDs survive — they are set in performInsert, not via events). Always pass the specific event classes: Event::fake([RegistrationConfirmed::class]). This bit two modules in one day.

## Pest helper functions cannot call protected TestCase methods
Plain `function` helpers in a Pest file run in global scope — `$test->withBrand()` on the passed TestCase fails with "Call to protected method". Call `$this->withBrand()` inside the test closure and pass the resulting model into the helper. Also: helper function names are process-global across ALL module test files in one run — prefix or scope them uniquely or the suite dies on redeclaration.
