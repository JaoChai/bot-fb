# A2 report — verified payment proof at financial consumers

## Status and scope

Implemented and self-reviewed on `feat/bot26-commerce-safety`, starting at the requested clean HEAD `40008868273554ec229276c3d62d171809b03888`. Commit: the commit containing this report, titled `fix: require payment proof at financial consumers`.

A2 only. No agents, external HTTP/network calls, production database access, customer messages, pushes, deployments, prompt changes, migrations, outbox, or `PaymentEffectDispatcher`. HTTP transports and queues in new integration tests are mocked/faked; database behavior uses the repository's SQLite in-memory test configuration. B3 proof identity, checkout binding, and settlement remain the producers of authority.

## Changes

- Added the single trusted `PaymentFlexService::fromVerifiedPayment(VerifiedPaymentEvent): array` entry point and event→checkout relation. It reloads event, bot, conversation, slip, receipt, actor, checkout, and Order. It verifies exact scope, receipt sender, amount/currency, provider reference/status, or manual actor ownership/status/receipt identity. It ignores supplied prose and stale model relations. B3's existing complete Order validator controls settled delivery copy; altered Orders, paid holds, and hold mode show received money pending staff review.
- Added `FinancialOutputGuard`. For enforce/hold, financial action-token presence is a conservative interlock before model proposal/payment parsing and persistence. Denials are exactly `รบกวนรอผลตรวจสอบการชำระเงินจากระบบหรือทีมงานครับ`; ORDER payload and checkout metadata are cleared. Clause-intent/negation/FAQ exceptions were removed, as adjudicated. Ordinary price information without action tokens remains available.
- Exact persisted B2 checkout challenges retain server authority through their checkout row and current rendered wording, not metadata flags. They are delivered as a single LINE text object with hidden ORDER markers removed; they do not re-enter legacy financial regex conversion. In-memory message mutations are restored to canonical text before plugins.
- Guarded ordinary AI, text, image, sticker, aggregation, direct Flex, and direct bubble consumers. Full-message sanitization happens before splitting and plugin execution. Enforce/hold always select the modern LINE pipeline regardless of legacy rollout flags. Off/shadow retain legacy conversion, original plain/bubble text, and plugin behavior.
- Automatic output retrieves B3's existing event without recording/rebinding it. Automatic, retry, and manual trusted LINE presentation runs after local commit. Manual's initial local receipt has the exact received amount and no premature delivery promise. Retry/manual do not invoke plugins or reservation. No new event, checkout rematching, text-derived Order, or stock job was added.
- Configured financial plugins are skipped before keyword/LLM evaluation in enforce/hold. Real nonfinancial plugin evaluation and Telegram delivery remain active. `OrderService::createFromPluginExtraction` returns null for enforce/hold, including alternate callers with a genuine receipt; B3's canonical Orders remain separate.

The explicit 21-file source/test inventory is in [scoped-files.txt](a2-test-output/scoped-files.txt). Additional files in this commit are this report and its local verification evidence.

## TDD and verification

Environment: PHP 8.5.2, PHPUnit 12.5.33. All PHPUnit commands used `php -d memory_limit=512M backend/vendor/bin/phpunit -c backend/phpunit.xml … --no-coverage` from the workspace root. Outputs below are exact PHPUnit summaries (ANSI styling omitted here); linked files preserve the captured output (the two large logs are gzip-compressed without altering their bytes).

| Run | Exact summary / outcome |
| --- | --- |
| Initial focused RED, before production edits | `Tests: 302, Assertions: 1709, Errors: 18, Failures: 12, PHPUnit Notices: 19, Skipped: 5, Risky: 11.` Exit 2. [Output](a2-test-output/red.txt.gz) |
| Follow-up RED: parser short-circuit and mutated Order copy | `Tests: 87, Assertions: 580, Errors: 1, Failures: 1, Skipped: 4, Risky: 1.` Exit 2. [Output](a2-test-output/red-followup.txt) |
| Follow-up RED: initial manual receipt and hold-mode copy | `Tests: 2, Assertions: 8, Failures: 2, Risky: 1.` Exit 1. [Output](a2-test-output/red-hold-copy.txt) |
| Self-review RED: in-memory canonical challenge mutation | `Tests: 1, Assertions: 3, Failures: 1.` Exit 1. [Output](a2-test-output/red-canonical-snapshot.txt) |
| Final expanded focused GREEN, after corrections and Pint | `Tests: 535, Assertions: 3949, PHPUnit Notices: 19, Skipped: 5.` Exit 0; no errors, failures, or risky tests. [Output](a2-test-output/green-final-expanded.txt) |
| Unit suite, run once with 512M | `Tests: 1080, Assertions: 3081, Failures: 122, PHPUnit Notices: 89, Skipped: 16.` Exit 1. All 122 failures were obsolete FAQ/negation expectations in the two detector/guard test files; see disposition below. [Output](a2-test-output/unit.txt.gz) |
| Pint on all changed PHP files, final check | Exit 0. [Output](a2-test-output/pint-check.txt) |
| `git diff --check` | Exit 0, no findings. [Output](a2-test-output/diff-check.txt) |

Initial focused RED and final GREEN reuse:

- `PaymentProofTest`, `CheckoutSettlementTest`, `CheckoutConsentTest`
- `SlipVerificationPipelineTest`, `SlipRetryServiceTest`, `ManualPaymentConfirmTest`
- `PaymentFlexServiceTest`, `LineWebhookOutputServiceTest`, `FlowPluginServiceTest`, `MultipleBubblesServiceTest`, `ProcessLINEWebhookPipelineTest`
- New `PaymentConsumersTest`

The final expanded GREEN also includes `FinancialOutputDetectorTest` and `FinancialOutputGuardTest` (218 cases). The single whole-unit run exposed 61 obsolete discussion expectations in each of these two files. Their full prior corpus was retained, with explicit expected decisions updated to the binding A2 rule; the three ordinary product-price cases remain allowed. No other unit failures occurred. All affected cases now pass in the final expanded GREEN. The whole unit suite was not run a second time, respecting the requested single run; a final all-unit GREEN in one invocation is therefore not claimed.

Final command:

```sh
php -d memory_limit=512M backend/vendor/bin/phpunit -c backend/phpunit.xml \
  backend/tests/Feature/CommerceSafety/PaymentConsumersTest.php \
  backend/tests/Feature/CommerceSafety/PaymentProofTest.php \
  backend/tests/Feature/CommerceSafety/CheckoutSettlementTest.php \
  backend/tests/Feature/CommerceSafety/CheckoutConsentTest.php \
  backend/tests/Feature/SlipVerificationPipelineTest.php \
  backend/tests/Feature/SlipRetryServiceTest.php \
  backend/tests/Feature/ManualPaymentConfirmTest.php \
  backend/tests/Unit/Services/PaymentFlexServiceTest.php \
  backend/tests/Unit/Services/LineWebhook/LineWebhookOutputServiceTest.php \
  backend/tests/Unit/Services/FlowPluginServiceTest.php \
  backend/tests/Unit/Services/MultipleBubblesServiceTest.php \
  backend/tests/Unit/Jobs/ProcessLINEWebhookPipelineTest.php \
  backend/tests/Unit/Services/CommerceSafety/FinancialOutputDetectorTest.php \
  backend/tests/Unit/Services/CommerceSafety/FinancialOutputGuardTest.php \
  --no-coverage --colors=never --display-phpunit-notices --display-skipped
```

## Coverage and self-review

- RED demonstrated the payment-first combined forgery and pure success leaks, forged metadata, receipt mismatch/cross-conversation borrowing, failed/mutated/stale slip state, revoked manual actor, direct Flex without Message, text/image/sticker with bubbles, aggregation, unguarded legacy routing, financial plugin evaluation, and missing retry/manual trusted presentation.
- Reject tests assert the exact denial, cleared ORDER metadata, zero Orders, zero reserve jobs, and no external requests. A real plugin matrix additionally proves zero configured-financial evaluator/Telegram activity in enforce/hold while nonfinancial evaluation remains active and cannot create Orders. Off/shadow run both configured plugin types.
- Actual AI persistence is checked before insertion and financial parsers/stock processing are prohibited for rejected model output. Automatic EasySlip uses fake provider data and persisted B3 proof; its output test explicitly holds an outer transaction and verifies no LINE presentation until commit. Retry/manual callbacks assert only the test harness transaction remains. Held receipt copy contains the received fractional amount and staff wording; settled copy derives canonical Page/G3D items and differs correctly.
- Reviewed every scoped `tryConvertToFlex`, `executePlugins`, and `createFromPluginExtraction` call site and the legacy alternate routes. No financial effect dispatcher, reservation dispatch, new proof recording, or checkout rematching was introduced. Financial proof rendering has local reads/validation only; LINE transport is outside its transaction.
- Self-review fixes were tested before correction: mutated Order/hold copy and canonical challenge memory mutation. No known unresolved A2 implementation defect remains. No independent agent review was performed, per instruction.

## Concerns / next-task handoff

1. Conservative action tokens intentionally deny safe financial FAQ, negation, and some unrelated English `pay`/`transfer` uses. The preserved corpus documents these C1 false positives; no semantic exception stacking was restored.
2. A2's trusted LINE handoff is temporary and nondurable. Worker replay may repeat a genuine receipt; a crash after commit can miss presentation. A3 owns durable effect identity/retry, Telegram payment effects, and stock reservation. This commit does not claim exactly-once notification delivery.
3. Five opt-in PostgreSQL cases were skipped in focused tests; 16 cases were skipped in the one unit run. The 19 focused notices originate from existing `FlowPluginServiceTest` mocks without expectations. The final output names every skipped case. No new PostgreSQL race or final whole-unit/full-backend green run is claimed here; prior ledger release blockers remain.
4. Commit and local implementation do not authorize rollout, push, production messages, or deployment.
