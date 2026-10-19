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
     * @return array{score: float, buying_score: float|null, buying_blocked: bool, handover: bool, mode: string, model: string, latency_ms: int, cost: float}|null
     *                                                                                                                                                             null = router off or support-call failure — reply as today
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

        try {
            $responses = Http::pool(function (Pool $pool) use ($headers, $history, $latestMessage, $model, $timeout, $url) {
                return [
                    'support' => $pool->as('support')->timeout($timeout)->withHeaders($headers)->post($url, $this->supportBody($model, $latestMessage, $history)),
                    'buying' => $pool->as('buying')->timeout($timeout)->withHeaders($headers)->post($url, $this->buyingBody($model, $latestMessage)),
                ];
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
     * Telegram alert to the admin on handover (never throws — no plugin = log and continue).
     */
    private function notifyAdmin(Bot $bot, string $latestMessage, float $score): void
    {
        try {
            $flow = $bot->defaultFlow;
            $plugin = $flow?->plugins()
                ->where('type', 'telegram')
                ->where('enabled', true)
                ->first();

            if (! $plugin) {
                Log::debug('Support router alert: no enabled telegram plugin', ['bot_id' => $bot->id]);

                return;
            }

            $token = $plugin->config['access_token'] ?? '';
            $chatId = $plugin->config['chat_id'] ?? '';
            if (empty($token) || empty($chatId)) {
                Log::debug('Support router alert: telegram plugin missing config', ['plugin_id' => $plugin->id]);

                return;
            }

            $text = '🛠 <b>ลูกค้าต้องการ Support</b> ('.TelegramAlertBotService::esc($bot->name).")\n"
                .'คะแนน '.TelegramAlertBotService::esc(number_format($score, 2))."\n"
                .TelegramAlertBotService::esc(mb_substr($latestMessage, 0, 300));

            app(TelegramAlertBotService::class)->sendMessage($token, $chatId, $text);
        } catch (\Throwable $e) {
            Log::warning('Support router alert failed', [
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
