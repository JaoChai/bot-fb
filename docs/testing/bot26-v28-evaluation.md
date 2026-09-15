# Bot 26 / Flow 24 — v28 evaluation

## Status (2026-09-15)

Offline replay and application integration pass. Raw inference has been **executed**
against the consent-vocabulary-fixed prompt (SHA-256 below): 31/38 text cases pass
their literal fixture assertions on first pass + one rerun of failing ids; see
[`bot26-v28-live-raw-eval-2026-09-15.md`](bot26-v28-live-raw-eval-2026-09-15.md) for
full per-case raw output, request IDs and cost. **Manual semantic review of these new
outputs is still required** before any production/E2E acceptance claim.
Image classification is **blocked**; T17 does not establish camera-photo recognition.
These results do not establish production E2E behavior or prompt semantic acceptance.

The measured inventory is committed at
`backend/tests/Fixtures/PromptEval/bot26-v28/manifest.json`. The offline inventory test
recomputes prompt and fixture character counts, byte counts and SHA-256 hashes.

| Artifact | Measurement |
| --- | --- |
| Prompt | `backend/resources/prompts/bot26/v28.txt` |
| Unicode characters | 23,598 |
| UTF-8 bytes | 59,969 |
| SHA-256 | `e132e40e070c3a5433796ee2d1732181bc637a1263462f61a349bccfb98254dd` |
| Fixtures | 41: T01–T33 and X01–X08 |
| Text replays | 38 |
| Image handler replays | 3: T16, T17, T30 |

## Evidence boundaries

### Offline

The 38 text responses are historical `regression-final2` evidence. Their recorded
prompt hash is `3cdc1c0a0e65d14a8a1b49997f1d524e66d2ce81b0bf7d884494a91ada54f3ec`,
which differs from the imported v28 artifact. Their saved request IDs, model,
reasoning effort, provider settings and finish reasons are checked as recorded
provenance; they were not independently reverified with a provider call in this run.
The three image fixtures have no prompt hash or raw-inference pass.

Offline assertions recompute fixture literals, prohibited strings, canonical prices,
cart arithmetic and final ORDER-block structure. Mutation checks reject incorrect
totals and invalid protocol placement. Fixture notes identify a historical T09
contact defect; the application includes a separate regression for that defect.

### Application

Synthetic Bot 26, Flow 24, catalog, conversations, memory and history are persisted
in the test database. Real response/output handlers, cart validation, checkout
authority and safety guards run with fake HTTP, queues and broadcasting.
No customer records or live service responses are used.

Each text fixture checks the persisted and LINE-visible reply. Fixture `contains`,
`not_contains`, items and totals remain the primary assertions for every case in the
`fixture_semantics` data provider, including guard replacements. ORDER/OFFTOPIC are checked at
their application boundaries rather than required in customer-visible text.
OFFTOPIC must increment its circuit-breaker counter. A fixture item price is not
treated as a requirement to print that unit price when the fixture only requires a
total. Missing items/totals do not acquire invented expectations.

T12–T15 and X06 encode consent stages in their histories and labels. Their initial
cart is parsed from the fixture's summary and persisted through `CheckoutAuthority`.
Historical consent messages are replayed against persisted, presented challenges.
The tests assert awaiting-support, awaiting-terms or payable state as appropriate;
T15 additionally checks persisted quantities/prices/total and the server ORDER
payload. T12/T13/T15 consume the current consent reply without an LLM call. Other
fixtures do not gain a paid checkout merely because historical bot prose says paid.

T32 persists a synthetic knowledge base, document and contradictory chunk attached
to Flow 24. Only retrieval is stubbed, returning the persisted chunk. The real RAG
prompt path must include that exact chunk in outbound LLM messages. The application
also checks injected stock, VIP pricing and the typed memory notes for T06/T07.
This proves injection plumbing, not retrieval ranking or model conflict resolution.

#### Existing guard replacements: semantic gaps

Stronger assertions exposed 17 historical replies whose original literals do not
survive existing production guards. All 38 cases run their fixture-derived assertions
against persisted and LINE-visible text without replacing `contains` or clearing
items/totals. Each run records the original assertions, display assertions and
`unmet_fixture_semantics` by output surface in
`backend/storage/app/prompt-eval/bot26-v28/application/{case_id}.json`.
The exact unmet failure lists must match the manifest's explicit
`layers.application.expected_unmet_fixture_semantics` entries; unlisted cases must
have no failures. New gaps, partial improvements and fully restored semantics all
fail until the manifest is updated. Reports are saved before that comparison, so
changed semantics remain inspectable even on failure.

Separate assertions check the exact guard replacement and zero checkout sessions.
The 17 cases are **not** counted as successful preservation of fixture semantics.
No production guard was disabled or changed to make the evaluation pass.

| Cases | Asserted application outcome |
| --- | --- |
| T01, T03, T04, T06, T07, T11, T20, X03, X04, X05 | Existing proposal parser rejects the prose; request explicit product, quantity and method |
| T19, T22, T23, T33, X02 | Financial-word interlock returns `FinancialOutputGuard::DENIAL`, including negated payment claims |
| T29 | Contact policy returns `CustomerReplyPolicy::FALLBACK` |
| X08 | Stock guard replaces the reply with the BM-unavailable response |

The remaining 21 text cases meet fixture literals/totals on actual application
output. Of the eight fixtures with expected totals, T15 and X07 preserve the expected
total in the displayed reply; the other six are among the explicit guard replacements.
X07 has no ORDER block, so its display-total check does not claim a persisted cart
revision. A separate synthetic checkout regression verifies revision changes,
reconfirmation and consent acceptance exactly once.

### Images

T16, T17 and T30 exercise image-handler reply handling with synthetic image URLs,
fake EasySlip responses and canned binary classifier outputs. They assert reply
literals and absence of orders, payment confirmation and fulfillment effects.
T16 additionally exercises the unreadable-slip/staff-alert branch.

T17 explicitly sets `classification_gate_blocked: true` with a reason: the current
binary `is_slip` contract has no camera-photo `image_kind`. Supplying `is_slip=false`
and a canned request for the original bank-app image proves only reply handling.
The test's raw-image skip-list and manifest retain this blocked classification gate.
No camera-photo, original-bank-app-image or Meta-screenshot recognition claim is made.

### Raw mode

`PromptEvalRunner::runRaw()` is an explicit raw evaluation entry point; existing
`run(Bot, case)` callers retain their AI/RAG display-response behavior. Raw mode sends
one HTTP request with no semantic cache, application sanitization, retries or model
fallback. It returns raw content, request ID, requested/returned model, wire settings,
finish reason, usage and input-message SHA-256. Missing provenance stays null.

The live test uses this runner and remains in group `prompt-eval-raw`, gated by the
exact environment value `BOT26_PROMPT_EVAL_LIVE=1`. It requests
`openai/gpt-5.6-luna`, reasoning effort `medium`, 8,192 maximum tokens, temperature
0.7 and `provider.allow_fallbacks=false`. A returned-model mismatch, missing
provenance or non-`stop` finish fails. Artifacts, if explicitly run in the future,
go to `backend/storage/app/prompt-eval/bot26-v28/` and retain raw output, input/prompt
hashes, settings, usage, assertions and pending manual semantic review. A fake-HTTP
test writes through the same artifact writer, reads the JSON back and checks exact
usage preservation, including token details and cost. Its temporary artifact is
removed after the test and never placed in the live-evidence directory.

Fake-HTTP raw transport tests check exact settings, unchanged protocol bytes,
missing/mismatched provenance, repeated uncached requests and HTTP failure without
retry/fallback; they do not count as live raw evaluation. A live raw run (real
OpenRouter calls, `BOT26_PROMPT_EVAL_LIVE=1`) has now been executed once against the
current prompt SHA-256; see
[`bot26-v28-live-raw-eval-2026-09-15.md`](bot26-v28-live-raw-eval-2026-09-15.md) for
full per-case raw output and request IDs. Its automatic pass/fail is literal-assertion
only — manual semantic review of the new outputs is still required and not yet done.

## Reproduction and validation

Run from `backend/`; these commands explicitly keep live inference disabled:

```sh
BOT26_PROMPT_EVAL_LIVE=0 vendor/bin/phpunit tests/Feature/PromptEval/Bot26PromptEvaluationTest.php --exclude-group prompt-eval-raw
BOT26_PROMPT_EVAL_LIVE=0 vendor/bin/phpunit --testsuite Unit
vendor/bin/pint tests/Feature/PromptEval/Bot26PromptEvaluationTest.php
git diff --check
```

Round 2 validation from `e4bdd53c`: the feature evaluation ran once and passed
90 tests / 2,657 assertions (40 offline, 47 application, three fake/gating raw checks).
The unit suite ran once and passed: 1,229 tests, 4,876 assertions, 70 PHPUnit notices
and 16 skips, with no failures/errors. The application artifacts retain all 38
original assertion sets and report exactly the 17 expected unmet cases.
Logs and JUnit reports are `/private/tmp/bot26-c2-round2-{feature,unit}.{log,xml}`;
the manifest records their exact paths. The eight existing evaluator command tests
(29 assertions) passed in the previous round and were not rerun in round 2.
Pint on the changed PHP test and `git diff --check` pass.
No live calls, customer data or `.superpowers` artifacts are part of this change.
