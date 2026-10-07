<?php

namespace App\Services\SupportRouter;

use App\Models\Bot;
use App\Services\OpenRouterCredentials;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Support Router (Luna Decisions) — B2.
 *
 * Before the bot replies, ask the OpenRouter Decisions API ONE yes/no question:
 * "is the latest customer message a post-sale problem for human Technical Support?".
 *
 * Modes (bot.support_router_mode):
 *  - off:    no HTTP call at all, decide() returns null.
 *  - shadow: call, record the score in message metadata, reply exactly as today.
 *  - on:     score >= threshold => handover (bot replies support_handover_message,
 *            admin gets a Telegram alert); otherwise reply as today.
 *
 * The router must never block or break a reply: ANY failure (timeout, non-2xx,
 * bad JSON, missing key, empty model) => log and return null => reply as today.
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
     * @return array{score: float, handover: bool, mode: string, model: string, latency_ms: int, cost: float}|null
     *                                                                                                             null = router off or any failure — reply as today
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

        try {
            $response = Http::timeout((int) config('services.openrouter.support_router_timeout', 5))
                ->withHeaders([
                    'Authorization' => 'Bearer '.$this->credentials->key(),
                ])
                ->post(config_string('services.openrouter.decisions_url', 'https://openrouter.ai/api/alpha/decisions'), [
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
                ]);

            if ($response->failed()) {
                Log::warning('Support router: Decisions API failed', [
                    'bot_id' => $bot->id,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $score = $response->json('answers.support.noul');

            if (! is_numeric($score)) {
                Log::warning('Support router: Decisions response missing numeric score', [
                    'bot_id' => $bot->id,
                    'body' => substr($response->body(), 0, 300),
                ]);

                return null;
            }

            $score = $this->clamp((float) $score);
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

        $threshold = (float) config('services.openrouter.support_router_threshold', 0.7);
        $handover = $mode === 'on' && $score >= $threshold;

        $decision = [
            'score' => $score,
            'handover' => $handover,
            'mode' => $mode,
            'model' => $model,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'cost' => (float) ($response->json('usage.cost') ?? 0.0),
        ];

        if ($handover) {
            $this->notifyAdmin($bot, $latestMessage, $score);
        }

        return $decision;
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
