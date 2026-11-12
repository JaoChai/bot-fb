<?php

namespace App\Services\SupportRouter;

use App\Models\Bot;
use App\Services\OpenRouterCredentials;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Support Router (Luna Decisions) — B2.
 *
 * Before the bot replies, ask the OpenRouter Decisions API TWO parallel yes/no
 * questions about the SAME latest customer message:
 *  - support: "is the latest message a post-sale problem for human Technical Support?"
 *    (asked WITH the recent conversation, exactly as before the buy gate existed);
 *  - buying:  "is the customer placing or continuing a NEW purchase right now?"
 *    (asked with ONLY the latest message — earlier complaints must not leak in).
 *
 * Modes (bot.support_router_mode):
 *  - off:    no HTTP call at all, decide() returns null.
 *  - shadow: call, record both scores in message metadata, reply exactly as today.
 *  - on:     support score >= threshold AND buying score < 0.5 => handover (bot
 *            replies support_handover_message, admin gets a Telegram alert);
 *            otherwise reply as today. Real new purchases that follow an earlier
 *            complaint score high on support but also >= 0.5 on buying, so the
 *            buy gate keeps them selling.
 *
 * The router must never block or break a reply: a SUPPORT failure => null =>
 * reply as today. A BUYING failure only yields buying_score null, which never
 * causes a handover (sales-safe).
 *
 * KB shadow measurement (LK2, config services.openrouter.support_router_kb_shadow):
 * when the flag is on, the same pool asks the "does this message need the
 * knowledge base?" question TWICE more (kb = latest message only, kb_ctx = with
 * the recent conversation) and records kb_score / kb_ctx_score in the decision
 * array (persisted to messages.metadata.support_router). NOTHING reads them —
 * the reply path, the handover rule, the intent step and the KB search stay
 * exactly as today. A failed kb call yields a null score only, with one warning
 * (bot id, status, error only — never customer text), and never changes any
 * existing key. Flag off (default) = today's two requests, byte-identical.
 */
class SupportRouterService
{
    /**
     * Exact question text — scored 0.89-0.93 precision on 308 real chats
     * (Lead verified 2026-10-07). Do not paraphrase: wording changes move the score.
     */
    private const QUESTION = 'Judging the LATEST customer message in context of the recent conversation: '
        .'is this topic present? Post-sale issue that the shop\'s human Technical Support must handle: '
        .'a problem with an account or product already bought (banned, locked, checkpoint, error, '
        .'login fails, wrong password, limit not rising, card binding fails), technical how-to after '
        .'purchase (setup, ads manager, pixel, campaigns), account credential checks, replacement/claim, '
        .'or \'support hasn\'t replied\'. Also true when the latest message continues an ongoing post-sale '
        .'issue from the recent conversation (follow-up answers, pasted account credentials/IDs/card numbers, '
        .'\'check this\').';

    /**
     * Exact buying question text (Lead measured 2026-10-19 on 61 real prod turns:
     * real purchases 0.75-0.95, real support cases max 0.33). Do not paraphrase:
     * wording changes move the score.
     */
    private const BUYING = 'Judging ONLY the LATEST customer message: is the customer placing or continuing a NEW purchase right now? '
        .'True for: naming a product or quantity to buy (\'buy 40\', \'I want 2 sets\', \'take that one\'), '
        .'confirming an order (\'confirm\'), choosing a product option before paying, asking price/stock in order to buy now. '
        .'False when the latest message reports a problem, asks for a claim/replacement, asks how to use something '
        .'already bought, or chases support, even if the customer bought earlier.';

    /**
     * Exact KB-shadow question text (Lead measured 2026-11-12 on 543 real bot-26
     * messages, LK1 blind read: Luna missed 3, live system 22 — card LK2, frozen).
     * Sent in BOTH shadow variants (kb without history, kb_ctx with history); the
     * answer is read from answers.kb.noul in both. Do not paraphrase: wording
     * changes move the score.
     */
    private const KB_QUESTION = 'Is the latest customer message a question that needs factual shop information to answer '
        .'(products, prices, stock, how to use or set up, policies, warranty, documentation)? Answer No for greetings, '
        .'casual talk, opinions, thanks, acknowledgements, confirmations, order placement, payment notices and short follow-ups.';

    /** A buying score at or above this blocks the support handover. */
    private const BUYING_BLOCK_THRESHOLD = 0.5;

    private const SHOP_LINE = 'Online shop chat. The bot sells; human Technical Support handles anything about products already bought.';

    /** How many messages of context the Decisions model sees before the latest message. */
    private const RECENT_LIMIT = 6;

    /** Each recent message is truncated to this many characters. */
    private const RECENT_TEXT_LIMIT = 300;

    public function __construct(
        private readonly OpenRouterCredentials $credentials,
    ) {}

    /**
     * @param  array<int, array{sender: string, content: string}>  $history  messages BEFORE
     *                                                                       $latestMessage (already deduplicated by the caller)
     * @return array{score: float, buying_score: float|null, buying_blocked: bool, handover: bool, mode: string, model: string, latency_ms: int, cost: float, kb_score?: float|null, kb_ctx_score?: float|null}|null
     *                                                                                                                                                                                                               null = router off or support-call failure — reply as today
     *                                                                                                                                                                                                               kb_score / kb_ctx_score are present ONLY when the KB shadow flag is on (LK2, recorded, nothing reads them)
     */
    public function decide(Bot $bot, string $latestMessage, array $history): ?array
    {
        $mode = $bot->support_router_mode ?? 'off';

        if (! in_array($mode, ['shadow', 'on'], true)) {
            return null;
        }

        $model = $bot->support_router_model;

        if (! is_string($model) || $model === '') {
            Log::warning('Support router: mode enabled but no decisions model set', ['bot_id' => $bot->id]);

            return null;
        }

        if (! $this->credentials->isConfigured()) {
            Log::warning('Support router: OPENROUTER_API_KEY is not set', ['bot_id' => $bot->id]);

            return null;
        }

        $startedAt = microtime(true);

        $timeout = (int) config('services.openrouter.support_router_timeout', 5);
        $url = config_string('services.openrouter.decisions_url', 'https://openrouter.ai/api/alpha/decisions');
        $headers = ['Authorization' => 'Bearer '.$this->credentials->key()];
        // LK2 shadow measurement: OFF (default) = today's two requests, byte-identical.
        $kbShadow = (bool) config('services.openrouter.support_router_kb_shadow', false);

        try {
            $responses = Http::pool(function (Pool $pool) use ($headers, $history, $kbShadow, $latestMessage, $model, $timeout, $url) {
                $requests = [
                    'support' => $pool->as('support')->timeout($timeout)->withHeaders($headers)->post($url, $this->supportBody($model, $latestMessage, $history)),
                    'buying' => $pool->as('buying')->timeout($timeout)->withHeaders($headers)->post($url, $this->buyingBody($model, $latestMessage)),
                ];

                if ($kbShadow) {
                    $requests['kb'] = $pool->as('kb')->timeout($timeout)->withHeaders($headers)->post($url, $this->kbBody($model, $latestMessage));
                    $requests['kb_ctx'] = $pool->as('kb_ctx')->timeout($timeout)->withHeaders($headers)->post($url, $this->kbCtxBody($model, $latestMessage, $history));
                }

                return $requests;
            });
        } catch (ConnectionException $e) {
            Log::warning('Support router: Decisions API timed out', [
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('Support router: request failed', [
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $supportScore = $this->supportScore($responses['support'] ?? null, $bot->id);

        // Fail-open: SUPPORT failure => null, no matter what the buying call did.
        if ($supportScore === null) {
            return null;
        }

        $buyingScore = $this->buyingScore($responses['buying'] ?? null, $bot->id);

        $threshold = (float) config('services.openrouter.support_router_threshold', 0.7);
        $buyingBlocked = $buyingScore !== null && $buyingScore >= self::BUYING_BLOCK_THRESHOLD;

        // A failed (null) buying check never causes a handover — sales-safe.
        $handover = $mode === 'on'
            && $supportScore >= $threshold
            && $buyingScore !== null
            && $buyingScore < self::BUYING_BLOCK_THRESHOLD;

        $decision = [
            'score' => $supportScore,
            'buying_score' => $buyingScore,
            'buying_blocked' => $buyingBlocked,
            'handover' => $handover,
            'mode' => $mode,
            'model' => $model,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'cost' => $this->costOf($responses['support'] ?? null) + $this->costOf($responses['buying'] ?? null),
        ];

        if ($kbShadow) {
            // LK2 shadow: recorded only — nothing branches on these values, a kb
            // failure lands here as a null score and changes no key above.
            $decision['kb_score'] = $this->kbScore($responses['kb'] ?? null, 'kb', $bot->id);
            $decision['kb_ctx_score'] = $this->kbScore($responses['kb_ctx'] ?? null, 'kb_ctx', $bot->id);
            $decision['cost'] += $this->costOf($responses['kb'] ?? null) + $this->costOf($responses['kb_ctx'] ?? null);
        }

        if ($handover) {
            $this->notifyAdmin($bot, $latestMessage, $supportScore);
        }

        return $decision;
    }

    /**
     * The support request — EXACTLY the body sent before the buy gate existed
     * (same state, same QUESTION constant, same answers.support.noul path).
     *
     * @param  array<int, array{sender: string, content: string}>  $history
     * @return array<string, mixed>
     */
    private function supportBody(string $model, string $latestMessage, array $history): array
    {
        return [
            'model' => $model,
            'state' => [
                'shop' => self::SHOP_LINE,
                'recent_conversation' => $this->recentConversation($history, $latestMessage),
                'latest_customer_message' => $latestMessage,
            ],
            'questions' => [
                'support' => [
                    'type' => 'noul',
                    'instructions' => self::QUESTION,
                    'criteria' => ['true' => 'Yes', 'false' => 'No'],
                ],
            ],
        ];
    }

    /**
     * The buying request — ONLY the latest customer message, no recent_conversation:
     * with history, the earlier complaint leaks in (real purchases scored 0.34-0.47).
     *
     * @return array<string, mixed>
     */
    private function buyingBody(string $model, string $latestMessage): array
    {
        return [
            'model' => $model,
            'state' => [
                'shop' => self::SHOP_LINE,
                'latest_customer_message' => $latestMessage,
            ],
            'questions' => [
                'buying' => [
                    'type' => 'noul',
                    'instructions' => self::BUYING,
                    'criteria' => ['true' => 'Yes', 'false' => 'No'],
                ],
            ],
        ];
    }

    /**
     * KB shadow request, "kb" variant — ONLY the latest customer message, no
     * recent_conversation (the shape the Lead measured on 2026-11-12). Same
     * question name as the ctx variant; the answer is read from answers.kb.noul.
     *
     * @return array<string, mixed>
     */
    private function kbBody(string $model, string $latestMessage): array
    {
        return [
            'model' => $model,
            'state' => [
                'shop' => self::SHOP_LINE,
                'latest_customer_message' => $latestMessage,
            ],
            'questions' => [
                'kb' => [
                    'type' => 'noul',
                    'instructions' => self::KB_QUESTION,
                    'criteria' => ['true' => 'Yes', 'false' => 'No'],
                ],
            ],
        ];
    }

    /**
     * KB shadow request, "kb_ctx" variant — same question name, the state adds
     * the recent conversation exactly as recentConversation() builds it for the
     * support call (last 6 messages, 300 chars each). State keys in this order:
     * shop, latest_customer_message, recent_conversation.
     *
     * @param  array<int, array{sender: string, content: string}>  $history
     * @return array<string, mixed>
     */
    private function kbCtxBody(string $model, string $latestMessage, array $history): array
    {
        return [
            'model' => $model,
            'state' => [
                'shop' => self::SHOP_LINE,
                'latest_customer_message' => $latestMessage,
                'recent_conversation' => $this->recentConversation($history, $latestMessage),
            ],
            'questions' => [
                'kb' => [
                    'type' => 'noul',
                    'instructions' => self::KB_QUESTION,
                    'criteria' => ['true' => 'Yes', 'false' => 'No'],
                ],
            ],
        ];
    }

    /**
     * Support score with today's exact fail-open semantics: null on ANY failure
     * (timeout, non-2xx, bad JSON, missing key), with the same log messages as before.
     */
    private function supportScore(mixed $response, int $botId): ?float
    {
        if ($response instanceof ConnectionException) {
            Log::warning('Support router: Decisions API timed out', [
                'bot_id' => $botId,
                'error' => $response->getMessage(),
            ]);

            return null;
        }

        if (! $response instanceof Response) {
            Log::warning('Support router: request failed', [
                'bot_id' => $botId,
                'error' => $response instanceof \Throwable ? $response->getMessage() : 'no response',
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('Support router: Decisions API failed', [
                'bot_id' => $botId,
                'status' => $response->status(),
            ]);

            return null;
        }

        $score = $response->json('answers.support.noul');

        if (! is_numeric($score)) {
            Log::warning('Support router: Decisions response missing numeric score', [
                'bot_id' => $botId,
                'body' => substr($response->body(), 0, 300),
            ]);

            return null;
        }

        return $this->clamp((float) $score);
    }

    /**
     * Buying score, or null when the buying call failed / returned a non-numeric
     * score — one warning logged, never throws. null never causes a handover.
     */
    private function buyingScore(mixed $response, int $botId): ?float
    {
        try {
            if ($response instanceof ConnectionException) {
                throw new RuntimeException($response->getMessage());
            }

            if (! $response instanceof Response) {
                throw new RuntimeException($response instanceof \Throwable ? $response->getMessage() : 'no response');
            }

            if ($response->failed()) {
                throw new RuntimeException('HTTP '.$response->status());
            }

            $score = $response->json('answers.buying.noul');

            if (! is_numeric($score)) {
                throw new RuntimeException('missing numeric score');
            }

            return $this->clamp((float) $score);
        } catch (\Throwable $e) {
            Log::warning('Support router: buying check failed', [
                'bot_id' => $botId,
                'status' => $response instanceof Response ? $response->status() : null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * KB shadow score, or null when that call failed — one warning per failed
     * call, never throws, never affects the reply decision. The warning logs
     * bot id, status and error ONLY (no customer text).
     *
     * @param  'kb'|'kb_ctx'  $question  which shadow variant failed, for the log message
     */
    private function kbScore(mixed $response, string $question, int $botId): ?float
    {
        try {
            if ($response instanceof ConnectionException) {
                throw new RuntimeException($response->getMessage());
            }

            if (! $response instanceof Response) {
                throw new RuntimeException($response instanceof \Throwable ? $response->getMessage() : 'no response');
            }

            if ($response->failed()) {
                throw new RuntimeException('HTTP '.$response->status());
            }

            $score = $response->json('answers.kb.noul');

            if (! is_numeric($score)) {
                throw new RuntimeException('missing numeric score');
            }

            return $this->clamp((float) $score);
        } catch (\Throwable $e) {
            Log::warning("Support router: KB shadow check failed ({$question})", [
                'bot_id' => $botId,
                'status' => $response instanceof Response ? $response->status() : null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function costOf(mixed $response): float
    {
        if (! $response instanceof Response) {
            return 0.0;
        }

        return (float) ($response->json('usage.cost') ?? 0.0);
    }

    /**
     * Last N text messages before the latest one, each text truncated.
     * Context is mandatory: without it recall dropped 0.74 -> 0.46.
     *
     * @param  array<int, array{sender: string, content: string}>  $history
     * @return array<int, array{from: string, text: string}>
     */
    private function recentConversation(array $history, string $latestMessage): array
    {
        // Drop the trailing duplicate of the latest message (callers that already
        // saved the current turn pass it at the end of $history) — recent_conversation
        // must contain only messages BEFORE the latest one.
        if (($history[array_key_last($history)] ?? []) === ['sender' => 'user', 'content' => $latestMessage]) {
            array_pop($history);
        }

        return collect($history)
            ->filter(fn (array $msg) => in_array($msg['sender'] ?? '', ['user', 'bot'], true)
                && is_string($msg['content'] ?? null)
                && $msg['content'] !== '')
            ->slice(-self::RECENT_LIMIT)
            ->values()
            ->map(fn (array $msg) => [
                'from' => $msg['sender'],
                'text' => mb_substr($msg['content'], 0, self::RECENT_TEXT_LIMIT),
            ])
            ->all();
    }

    private function clamp(float $score): float
    {
        return max(0.0, min(1.0, $score));
    }

    /**
     * Telegram alert to the admin on handover — thin wrapper over the shared
     * notifySupportAlert() (the alert code itself lives in TelegramAlertBotService,
     * shared with the vision image path). Router alert text unchanged.
     */
    private function notifyAdmin(Bot $bot, string $latestMessage, float $score): void
    {
        app(TelegramAlertBotService::class)->notifySupportAlert(
            $bot,
            'คะแนน '.number_format($score, 2),
            $latestMessage,
        );
    }
}
