<?php

namespace App\Http\Controllers\Webhook;

use App\Exceptions\DeliveryAlreadyHandledException;
use App\Exceptions\NoPendingPaymentException;
use App\Exceptions\RecentManualConfirmException;
use App\Http\Controllers\Controller;
use App\Models\AccountDelivery;
use App\Models\CheckoutSession;
use App\Models\Conversation;
use App\Models\FlowPlugin;
use App\Models\SlipVerification;
use App\Services\CommerceSafety\SafetyScope;
use App\Services\Delivery\AccountDeliveryService;
use App\Services\Payment\ManualPaymentConfirmService;
use App\Services\Payment\PaymentMessageDetector;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramAlertCallbackController extends Controller
{
    public function __construct(
        private readonly ManualPaymentConfirmService $confirmService,
        private readonly TelegramAlertBotService $alertBot,
        private readonly AccountDeliveryService $deliveryService,
    ) {}

    public function handle(Request $request, string $token): JsonResponse
    {
        $secret = (string) config('services.telegram_alert.secret');
        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');
        if ($secret === '' || ! hash_equals($secret, $provided)) {
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
        if (! in_array(count($parts), [3, 4], true)) {
            return response()->json(['ok' => true]);
        }
        $act = $parts[0];

        // action งานส่งของ: ส่วนที่สองของ callback_data เป็น delivery id ไม่ใช่ conversation id
        if (in_array($act, ['dv', 'dx', 'dz'], true)) {
            if (count($parts) !== 3 || ! is_numeric($parts[1]) || ! $this->isAuthorizedUser($plugin, $cb)) {
                return $this->rejectUnauthorized($plugin, $cb, $token);
            }

            return $this->handleDeliveryAction($act, (int) $parts[1], $plugin, $cb, $token);
        }

        // action เลือกรายการ: ส่วนที่สองเป็น slip_verifications id ไม่ใช่ conversation id
        if ($act === 'po') {
            if (count($parts) !== 3 || ! is_numeric($parts[1]) || ! $this->isAuthorizedUser($plugin, $cb)) {
                return $this->rejectUnauthorized($plugin, $cb, $token);
            }

            return $this->handlePickOption((int) $parts[1], (int) $parts[2], $plugin, $cb, $token, $chatId);
        }

        if (! in_array($act, ['pa', 'pc'], true)) {
            return response()->json(['ok' => true]);
        }

        $checkout = null;
        if (count($parts) === 4) {
            [, $checkoutId, $revision, $amt] = $parts;
            if (! ctype_digit($revision)) {
                return response()->json(['ok' => true]);
            }
            $checkout = CheckoutSession::query()
                ->whereKey($checkoutId)
                ->where('revision', (int) $revision)
                ->first();
            $conversation = $checkout?->conversation;
        } else {
            [, $convId, $amt] = $parts;
            if (! is_numeric($convId)) {
                return response()->json(['ok' => true]);
            }
            $conversation = Conversation::find((int) $convId);
        }

        if (! $conversation) {
            $this->alertBot->answerCallbackQuery($token, $cb['id'] ?? '', 'ไม่พบแชท');

            return response()->json(['ok' => true]);
        }

        if ($conversation->bot_id !== $plugin->flow?->bot_id) {
            Log::warning('Telegram alert callback: conversation/plugin bot mismatch', [
                'conversation_id' => $conversation->id,
                'plugin_id' => $plugin->id,
            ]);

            return response()->json(['ok' => true]);
        }

        $bot = $conversation->bot;
        $scoped = in_array(app(SafetyScope::class)->mode($bot), ['enforce', 'hold'], true);
        if ($scoped) {
            $actorId = $this->mappedApplicationActor($plugin, $cb, (int) $bot->user_id);
            if ($checkout === null || count($parts) !== 4 || $actorId === null
                || (int) $checkout->bot_id !== (int) $bot->id
                || (int) $checkout->conversation_id !== (int) $conversation->id) {
                return $this->rejectUnauthorized($plugin, $cb, $token);
            }
        } else {
            if (! $this->isAuthorizedUser($plugin, $cb)) {
                return $this->rejectUnauthorized($plugin, $cb, $token);
            }
            $actorId = (int) $bot->user_id;
        }

        $messageId = (int) ($cb['message']['message_id'] ?? 0);
        $fromName = $cb['from']['first_name'] ?? 'admin';
        $cbId = $cb['id'] ?? '';

        // เคส fraud กดครั้งแรก: แค่แก้ปุ่มให้ยืนยันชั้นสอง ยังไม่ทำงาน
        if ($act === 'pa') {
            $confirmData = $scoped
                ? "pc|{$checkout->id}|{$checkout->revision}|{$amt}"
                : "pc|{$conversation->id}|{$amt}";
            $this->alertBot->editMessageText($token, $chatId, $messageId,
                "⚠️ <b>ยืนยันทั้งที่สลิปน่าสงสัย?</b>\nกดปุ่มด้านล่างอีกครั้งเพื่อยืนยันจริง",
                [[['text' => '❗ กดอีกครั้งเพื่อยืนยันจริง', 'callback_data' => $confirmData]]],
            );
            $this->alertBot->answerCallbackQuery($token, $cbId, 'กดอีกครั้งเพื่อยืนยัน');

            return response()->json(['ok' => true]);
        }

        $amount = $amt === 'x' ? null : ($scoped ? $amt : (float) $amt);

        try {
            if ($scoped) {
                $this->confirmService->confirm(
                    $bot,
                    $conversation,
                    $amount,
                    $actorId,
                    null,
                    $checkout->id,
                    $checkout->revision,
                );
            } else {
                $this->confirmService->confirm($bot, $conversation, $amount, $actorId);
            }
            $this->alertBot->editMessageText($token, $chatId, $messageId,
                '✅ <b>ยืนยันรับเงินแล้ว</b> โดย '.TelegramAlertBotService::esc($fromName));
            $this->alertBot->answerCallbackQuery($token, $cbId, 'ยืนยันรับเงินแล้ว');
        } catch (RecentManualConfirmException $e) {
            $this->alertBot->editMessageText($token, $chatId, $messageId,
                '✅ <b>ยืนยันไปแล้ว</b> (โดยคนอื่นหรือทางเว็บ)');
            $this->alertBot->answerCallbackQuery($token, $cbId, 'ยืนยันไปแล้ว');
        } catch (NoPendingPaymentException $e) {
            $this->alertBot->answerCallbackQuery($token, $cbId, 'หายอดออเดอร์ไม่พบ กรุณายืนยันในเว็บ');
        } catch (\Throwable $e) {
            Log::error('Telegram alert confirm failed', ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);
            $this->alertBot->answerCallbackQuery($token, $cbId, 'เกิดข้อผิดพลาด ลองใหม่หรือยืนยันในเว็บ');
        }

        return response()->json(['ok' => true]);
    }

    /**
     * user ที่กดปุ่มได้รับอนุญาตไหม — config['authorized_user_ids'] ว่าง/ไม่ตั้ง = อนุญาตทุกคน
     * (backward-compat) ถ้าตั้งแล้วต้องเป็น Telegram from.id ที่อยู่ใน allowlist
     */
    private function isAuthorizedUser(FlowPlugin $plugin, array $cb): bool
    {
        $allow = $plugin->config['authorized_user_ids'] ?? [];
        if (! is_array($allow) || $allow === []) {
            return true;
        }
        $fromId = (string) ($cb['from']['id'] ?? '');

        return $fromId !== '' && in_array($fromId, array_map('strval', $allow), true);
    }

    private function mappedApplicationActor(FlowPlugin $plugin, array $cb, int $ownerId): ?int
    {
        $telegramId = (string) ($cb['from']['id'] ?? '');
        $mapping = $plugin->config['authorized_user_mappings'] ?? null;
        if ($telegramId === '' || ! is_array($mapping) || ! array_key_exists($telegramId, $mapping)) {
            return null;
        }
        $mapped = $mapping[$telegramId];
        if (! is_int($mapped) && (! is_string($mapped) || ! ctype_digit($mapped))) {
            return null;
        }
        $actorId = (int) $mapped;

        return $actorId === $ownerId ? $actorId : null;
    }

    private function rejectUnauthorized(FlowPlugin $plugin, array $cb, string $token): JsonResponse
    {
        Log::warning('Telegram alert callback: unauthorized user or stale authority', [
            'plugin_id' => $plugin->id,
            'from_id' => $cb['from']['id'] ?? null,
        ]);
        $this->alertBot->answerCallbackQuery($token, $cb['id'] ?? '', 'ไม่มีสิทธิ์กดยืนยัน');

        return response()->json(['ok' => true]);
    }

    private function handleDeliveryAction(
        string $act,
        int $deliveryId,
        FlowPlugin $plugin,
        array $cb,
        string $token,
    ): JsonResponse {
        $chatId = (string) ($cb['message']['chat']['id'] ?? '');
        $messageId = (int) ($cb['message']['message_id'] ?? 0);
        $fromName = $cb['from']['first_name'] ?? 'admin';
        $cbId = $cb['id'] ?? '';

        $escapedFrom = TelegramAlertBotService::esc($fromName);

        $delivery = AccountDelivery::find($deliveryId);
        if (! $delivery) {
            $this->alertBot->answerCallbackQuery($token, $cbId, 'ไม่พบงานส่งของ');

            return response()->json(['ok' => true]);
        }
        if ($delivery->bot_id !== $plugin->flow?->bot_id) {
            Log::warning('Delivery callback: delivery/plugin bot mismatch', [
                'delivery_id' => $delivery->id, 'plugin_id' => $plugin->id,
            ]);

            return response()->json(['ok' => true]);
        }

        // ยกเลิกขั้นแรก: แค่เปลี่ยนปุ่มเป็นยืนยันชั้นสอง (pattern เดียวกับ pa)
        if ($act === 'dx') {
            $this->alertBot->editMessageText($token, $chatId, $messageId,
                "⚠️ <b>ยืนยันยกเลิก คืนของเข้า stock?</b> · งาน #{$delivery->id}\nกดปุ่มด้านล่างอีกครั้งเพื่อยืนยันจริง",
                [[['text' => '❗ กดอีกครั้งเพื่อคืนของเข้า stock', 'callback_data' => "dz|{$delivery->id}|x"]]],
            );
            $this->alertBot->answerCallbackQuery($token, $cbId, 'กดอีกครั้งเพื่อยืนยัน');

            return response()->json(['ok' => true]);
        }

        try {
            if ($act === 'dz') {
                $this->deliveryService->cancel($delivery, $fromName);
                $this->alertBot->editMessageText($token, $chatId, $messageId,
                    "↩️ <b>คืนของเข้า stock แล้ว</b> โดย {$escapedFrom} · งาน #{$delivery->id}");
                $this->alertBot->answerCallbackQuery($token, $cbId, 'คืนของแล้ว');
            } else { // dv
                $this->deliveryService->deliver($delivery, $fromName);
                // ต่อท้ายคำเตือน shortage/unmapped ไม่ให้หายตอนแทนที่การ์ด (ลูกค้าจ่ายครบแต่ได้ไม่ครบ)
                $note = $this->deliveryService->pendingManualNote($delivery);
                $this->alertBot->editMessageText($token, $chatId, $messageId,
                    "✅ <b>ส่งให้ลูกค้าแล้ว</b> โดย {$escapedFrom} · งาน #{$delivery->id}".$note);
                $this->alertBot->answerCallbackQuery($token, $cbId, 'ส่งแล้ว');
            }
        } catch (DeliveryAlreadyHandledException $e) {
            $this->alertBot->editMessageText($token, $chatId, $messageId,
                "✅ งาน #{$delivery->id} ถูกจัดการไปแล้ว (สถานะ: ".TelegramAlertBotService::esc($delivery->fresh()->status).')');
            $this->alertBot->answerCallbackQuery($token, $cbId, 'จัดการไปแล้ว');
        } catch (\Throwable $e) {
            Log::error('Delivery callback action failed', [
                'delivery_id' => $delivery->id, 'action' => $act, 'error' => $e->getMessage(),
            ]);
            $fresh = $delivery->fresh();
            if ($act === 'dz' && $fresh->status === AccountDelivery::STATUS_CANCELED) {
                // cancel สำเร็จแต่คืนของเข้า stock ไม่สำเร็จ — ห้ามหลอกว่ากดใหม่ได้
                $this->alertBot->editMessageText($token, $chatId, $messageId,
                    "↩️ ยกเลิกงาน #{$delivery->id} แล้ว แต่คืนของเข้า stock ไม่สำเร็จ\nของยังค้างอยู่ในตารางจอง — ระบบตรวจ (delivery:reconcile) จะแจ้งเตือนซ้ำ อย่าเพิ่งขายชิ้นนี้ซ้ำ");
                $this->alertBot->answerCallbackQuery($token, $cbId, 'ยกเลิกแล้ว แต่คืนของไม่สำเร็จ');
            } else {
                $this->alertBot->editMessageText($token, $chatId, $messageId,
                    "❌ ทำไม่สำเร็จ — กดลองใหม่ได้ (งาน #{$delivery->id})",
                    $this->deliveryService->cardKeyboard($delivery));
                $this->alertBot->answerCallbackQuery($token, $cbId, 'เกิดข้อผิดพลาด ลองใหม่');
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * เจ้าของเลือกรายการที่ถูกต้องจากการ์ด "โอนข้ามขั้นตอน" → ยืนยันรับเงินด้วยรายการนั้น
     */
    private function handlePickOption(
        int $slipId,
        int $index,
        FlowPlugin $plugin,
        array $cb,
        string $token,
        string $chatId,
    ): JsonResponse {
        $messageId = (int) ($cb['message']['message_id'] ?? 0);
        $cbId = $cb['id'] ?? '';
        $fromName = $cb['from']['first_name'] ?? 'admin';

        $slip = SlipVerification::find($slipId);
        $conversation = $slip?->conversation_id ? Conversation::find($slip->conversation_id) : null;
        if ($slip === null || $conversation === null || $conversation->bot_id !== $plugin->flow?->bot_id) {
            $this->alertBot->answerCallbackQuery($token, $cbId, 'ไม่พบรายการนี้');

            return response()->json(['ok' => true]);
        }

        $items = $slip->reconstructed['alternatives'][$index] ?? null;
        if ($items === null) {
            $this->alertBot->answerCallbackQuery($token, $cbId, 'ตัวเลือกไม่ถูกต้อง');

            return response()->json(['ok' => true]);
        }

        $bot = $conversation->bot;

        try {
            $this->confirmService->confirm($bot, $conversation, (float) $slip->amount, $bot->user_id, $items);
            $summary = PaymentMessageDetector::formatItemSummary($items);
            $this->alertBot->editMessageText($token, $chatId, $messageId,
                '✅ <b>ยืนยันแล้ว: '.TelegramAlertBotService::esc($summary).'</b> โดย '.TelegramAlertBotService::esc($fromName));
            $this->alertBot->answerCallbackQuery($token, $cbId, 'ยืนยันแล้ว');
        } catch (RecentManualConfirmException) {
            $this->alertBot->editMessageText($token, $chatId, $messageId, '✅ <b>ยืนยันไปแล้ว</b> (โดยคนอื่นหรือทางเว็บ)');
            $this->alertBot->answerCallbackQuery($token, $cbId, 'ยืนยันไปแล้ว');
        } catch (\Throwable $e) {
            Log::error('Telegram alert pick option failed', ['slip_id' => $slipId, 'error' => $e->getMessage()]);
            $this->alertBot->answerCallbackQuery($token, $cbId, 'เกิดข้อผิดพลาด ลองใหม่หรือยืนยันในเว็บ');
        }

        return response()->json(['ok' => true]);
    }
}
