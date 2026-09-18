# Bot 26 / Flow 24 — v28 evaluation

## Status (2026-09-16)

Offline replay and application integration pass. Raw inference has been **executed**
against the prompt measured below, twelve full runs of all 38 text cases (456 live calls,
six per artifact) — see
[`bot26-v28-live-raw-eval-2026-09-16.md`](bot26-v28-live-raw-eval-2026-09-16.md) for the
full method, per-case failure distribution and cost. **Manual semantic review is done:
35 ACCEPT, 3 CONCERN, 0 REJECT** across all 38 cases, with the prompt-injection and
payment-claim safety checks holding.

**The literal 38/38 raw criterion was retired by owner ruling on 2026-09-16.** It was not
met by any run: the same prompt against the same fixtures produced between 2 and 12 failing
cases run to run, because the fixtures assert exact Thai substrings against a model sampled
at temperature 0.7. Manual semantic review is now the gate — every one of the 38 responses
is reviewed and a single REJECT blocks; the literals are a recorded smoke signal, while the
deterministic raw checks (forbidden strings, contact allowlist, cart arithmetic, ORDER-block
structure, `finish_reason`, wire settings) still fail a case on their own.

**Under that criterion the artifact FAILED on four cases**: T13 (refuses a valid `ยอมรับ`
on the Support-Delay gate and never reaches TERMS), T11 (redundant BM5 clarification the
fixture exists to forbid), T26 (agrees to write code for an out-of-scope request), T07
(omits the mandatory initial-Limit disclosure). **T26 and T07 passed every literal
assertion** and were caught only by review.

**T13 is now fixed** — it stalled the flow in 8 of 15 runs and now stalls in 0 of 15; see
the addendum in the evidence doc. **T11, T26 and T07 remain open, so the raw gate still
fails.** T11 reproduces at the same rate against the artifact as committed at `639867bd`,
so it is a pre-existing defect, as T13 was.

Image classification now runs through an `image_kind` contract (bot 26 only) with
canned classifier output; live camera-photo recognition is still unverified.
These results do not establish production E2E behavior.

The measured inventory is committed at
`backend/tests/Fixtures/PromptEval/bot26-v28/manifest.json`. The offline inventory test
recomputes prompt and fixture character counts, byte counts and SHA-256 hashes.

| Artifact | Measurement |
| --- | --- |
| Prompt | `backend/resources/prompts/bot26/v28.txt` |
| Unicode characters | 24,916 |
| UTF-8 bytes | 63,473 |
| SHA-256 | `b88b8619faa911e2e76f92a08389c85dd695a02bc213bc272a93356bbdbd3473` |
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

The bot-26 classifier now returns an `image_kind` (`bank_app_slip`,
`camera_photo_of_screen`, `other`) alongside `is_slip`, so the three fixtures drive a
real contract instead of the former blocked gate. Proven here: `bank_app_slip` reaches
the existing verification path; `camera_photo_of_screen` returns the fixed
`CAMERA_PHOTO_SLIP_TEMPLATE` reply and creates no order, payment event or effect;
`other` keeps the previous behavior; an unknown kind, malformed JSON or a classifier
transport failure fails closed to manual review. Other bots keep the original two-key
schema and behavior.

Still not proven: every classification in these tests is canned. No live vision model
was asked to tell a camera photo of a screen from a native bank-app screenshot, so
camera-photo, original-bank-app-image and Meta-screenshot recognition remain
unverified and need a staged check with real images.

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
