<?php

namespace App\Http\Controllers\Webhook;

use App\Exceptions\NoPendingPaymentException;
use App\Exceptions\RecentManualConfirmException;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\FlowPlugin;
use App\Services\Payment\ManualPaymentConfirmService;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramAlertCallbackController extends Controller
{
    public function __construct(
        private readonly ManualPaymentConfirmService $confirmService,
        private readonly TelegramAlertBotService $alertBot,
    ) {}

    public function handle(Request $request, string $token): JsonResponse
    {
        $secret = config('services.telegram_alert.secret');
        if ($secret && $request->header('X-Telegram-Bot-Api-Secret-Token') !== $secret) {
            return response()->json(['ok' => false], 401);
        }

        $plugin = FlowPlugin::where('type', 'telegram')
            ->where('enabled', true)
            ->where('config->access_token', $token)
            ->first();
        if (! $plugin) {
            return response()->json(['ok' => false], 404);
        }

        $cb = $request->input('callback_query');
        if (! is_array($cb)) {
            return response()->json(['ok' => true]);
        }

        $chatId = (string) ($cb['message']['chat']['id'] ?? '');
        if ($chatId !== (string) ($plugin->config['chat_id'] ?? '')) {
            Log::warning('Telegram alert callback: chat_id mismatch', ['got' => $chatId]);

            return response()->json(['ok' => true]);
        }

        $parts = explode('|', (string) ($cb['data'] ?? ''));
        if (count($parts) !== 3) {
            return response()->json(['ok' => true]);
        }
        [$act, $convId, $amt] = $parts;

        $conversation = Conversation::find($convId);
        if (! $conversation) {
            $this->alertBot->answerCallbackQuery($token, $cb['id'] ?? '', 'ไม่พบแชท');

            return response()->json(['ok' => true]);
        }

        $messageId = (int) ($cb['message']['message_id'] ?? 0);
        $fromName = $cb['from']['first_name'] ?? 'admin';
        $cbId = $cb['id'] ?? '';

        // เคส fraud กดครั้งแรก: แค่แก้ปุ่มให้ยืนยันชั้นสอง ยังไม่ทำงาน
        if ($act === 'pa') {
            $this->alertBot->editMessageText($token, $chatId, $messageId,
                "⚠️ ยืนยันทั้งที่สลิปน่าสงสัย?\nกดปุ่มด้านล่างอีกครั้งเพื่อยืนยันจริง",
                [[['text' => '❗ กดอีกครั้งเพื่อยืนยันจริง', 'callback_data' => "pc|{$convId}|{$amt}"]]],
            );
            $this->alertBot->answerCallbackQuery($token, $cbId, 'กดอีกครั้งเพื่อยืนยัน');

            return response()->json(['ok' => true]);
        }

        if ($act !== 'pc') {
            return response()->json(['ok' => true]);
        }

        $amount = $amt === 'x' ? null : (float) $amt;
        $bot = $conversation->bot;

        try {
            $this->confirmService->confirm($bot, $conversation, $amount, $bot->user_id);
            $this->alertBot->editMessageText($token, $chatId, $messageId,
                "✅ ยืนยันรับเงินแล้ว โดย {$fromName}");
            $this->alertBot->answerCallbackQuery($token, $cbId, 'ยืนยันรับเงินแล้ว');
        } catch (RecentManualConfirmException $e) {
            $this->alertBot->editMessageText($token, $chatId, $messageId,
                '✅ ยืนยันไปแล้ว (โดยคนอื่นหรือทางเว็บ)');
            $this->alertBot->answerCallbackQuery($token, $cbId, 'ยืนยันไปแล้ว');
        } catch (NoPendingPaymentException $e) {
            $this->alertBot->answerCallbackQuery($token, $cbId, 'หายอดออเดอร์ไม่พบ กรุณายืนยันในเว็บ');
        } catch (\Throwable $e) {
            Log::error('Telegram alert confirm failed', ['conversation_id' => $convId, 'error' => $e->getMessage()]);
            $this->alertBot->answerCallbackQuery($token, $cbId, 'เกิดข้อผิดพลาด ลองใหม่หรือยืนยันในเว็บ');
        }

        return response()->json(['ok' => true]);
    }
}
