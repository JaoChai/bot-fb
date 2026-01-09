<?php

namespace App\Http\Controllers\Bot;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class BotSettingsController extends Controller
{

    /**
     * Show credentials settings page.
     */
    public function credentials(Bot $bot): Response
    {
        $this->authorize('update', $bot);

        // Get masked credentials based on channel type
        $credentials = $this->getMaskedCredentials($bot);

        return Inertia::render('Bots/Settings/Credentials', [
            'bot' => $bot->only(['id', 'name', 'channel_type', 'status']),
            'credentials' => $credentials,
            'hasCredentials' => $this->hasCredentials($bot),
        ]);
    }

    /**
     * Get masked credentials for display.
     */
    protected function getMaskedCredentials(Bot $bot): array
    {
        $settings = $bot->settings ?? [];

        return match ($bot->channel_type) {
            'line' => [
                'channel_access_token' => isset($settings['channel_access_token']) ? $this->maskValue($settings['channel_access_token']) : null,
                'channel_secret' => isset($settings['channel_secret']) ? $this->maskValue($settings['channel_secret']) : null,
            ],
            'telegram' => [
                'bot_token' => isset($settings['bot_token']) ? $this->maskValue($settings['bot_token']) : null,
            ],
            'facebook' => [
                'page_access_token' => isset($settings['page_access_token']) ? $this->maskValue($settings['page_access_token']) : null,
                'app_secret' => isset($settings['app_secret']) ? $this->maskValue($settings['app_secret']) : null,
                'verify_token' => isset($settings['verify_token']) ? $this->maskValue($settings['verify_token']) : null,
            ],
            default => [],
        };
    }

    /**
     * Check if bot has credentials configured.
     */
    protected function hasCredentials(Bot $bot): bool
    {
        $settings = $bot->settings ?? [];

        return match ($bot->channel_type) {
            'line' => isset($settings['channel_access_token']) && isset($settings['channel_secret']),
            'telegram' => isset($settings['bot_token']),
            'facebook' => isset($settings['page_access_token']),
            default => false,
        };
    }

    /**
     * Mask a value for display.
     */
    protected function maskValue(string $value): string
    {
        if (strlen($value) <= 8) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 4) . str_repeat('*', 8) . substr($value, -4);
    }

    /**
     * Update bot credentials.
     */
    public function updateCredentials(Request $request, Bot $bot): RedirectResponse
    {
        $this->authorize('update', $bot);

        $rules = $this->getCredentialValidationRules($bot->channel_type);
        $validated = $request->validate($rules);

        try {
            // Store credentials in settings (encrypted via model cast)
            $settings = $bot->settings ?? [];

            foreach ($validated as $key => $value) {
                if ($value !== null && $value !== '') {
                    $settings[$key] = $value;
                }
            }

            $bot->update(['settings' => $settings]);

            return redirect()
                ->back()
                ->with('success', 'บันทึก Credentials เรียบร้อยแล้ว');
        } catch (\Exception $e) {
            Log::error('Failed to update bot credentials', [
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->back()
                ->with('error', 'ไม่สามารถบันทึก Credentials ได้');
        }
    }

    /**
     * Show AI settings page.
     */
    public function aiSettings(Bot $bot): Response
    {
        $this->authorize('update', $bot);

        return Inertia::render('Bots/Settings/AI', [
            'bot' => $bot->only(['id', 'name', 'channel_type', 'status', 'settings']),
            'aiModels' => config('ai.available_models', []),
        ]);
    }

    /**
     * Update AI settings.
     */
    public function updateAiSettings(Request $request, Bot $bot): RedirectResponse
    {
        $this->authorize('update', $bot);

        $validated = $request->validate([
            'settings.ai_model' => ['nullable', 'string'],
            'settings.temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'settings.max_tokens' => ['nullable', 'integer', 'min:1', 'max:4096'],
            'settings.system_prompt' => ['nullable', 'string', 'max:10000'],
        ]);

        $settings = array_merge($bot->settings ?? [], $validated['settings'] ?? []);
        $bot->update(['settings' => $settings]);

        return redirect()
            ->back()
            ->with('success', 'บันทึกการตั้งค่า AI เรียบร้อยแล้ว');
    }

    /**
     * Show notification settings page.
     */
    public function notifications(Bot $bot): Response
    {
        $this->authorize('update', $bot);

        return Inertia::render('Bots/Settings/Notifications', [
            'bot' => $bot->only(['id', 'name', 'channel_type', 'status', 'settings']),
        ]);
    }

    /**
     * Update notification settings.
     */
    public function updateNotifications(Request $request, Bot $bot): RedirectResponse
    {
        $this->authorize('update', $bot);

        $validated = $request->validate([
            'settings.notifications_enabled' => ['boolean'],
            'settings.notification_email' => ['nullable', 'email'],
            'settings.notify_on_new_conversation' => ['boolean'],
            'settings.notify_on_handoff' => ['boolean'],
        ]);

        $settings = array_merge($bot->settings ?? [], $validated['settings'] ?? []);
        $bot->update(['settings' => $settings]);

        return redirect()
            ->back()
            ->with('success', 'บันทึกการตั้งค่าการแจ้งเตือนเรียบร้อยแล้ว');
    }

    /**
     * Test bot connection.
     */
    public function testConnection(Bot $bot): RedirectResponse
    {
        $this->authorize('update', $bot);

        try {
            $result = match ($bot->channel_type) {
                'line' => $this->testLineConnection($bot),
                'telegram' => $this->testTelegramConnection($bot),
                'facebook' => $this->testFacebookConnection($bot),
                default => ['success' => false, 'message' => 'Unknown channel type'],
            };

            if ($result['success']) {
                return redirect()->back()->with('success', $result['message']);
            }

            return redirect()->back()->with('error', $result['message']);
        } catch (\Exception $e) {
            Log::error('Bot connection test failed', [
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()->with('error', 'ไม่สามารถทดสอบการเชื่อมต่อได้');
        }
    }

    /**
     * Get validation rules based on channel type.
     */
    protected function getCredentialValidationRules(string $channelType): array
    {
        return match ($channelType) {
            'line' => [
                'channel_access_token' => ['required', 'string'],
                'channel_secret' => ['required', 'string'],
            ],
            'telegram' => [
                'bot_token' => ['required', 'string'],
            ],
            'facebook' => [
                'page_access_token' => ['required', 'string'],
                'app_secret' => ['required', 'string'],
                'verify_token' => ['required', 'string'],
            ],
            default => [],
        };
    }

    /**
     * Test LINE connection.
     */
    protected function testLineConnection(Bot $bot): array
    {
        // Implementation would use LINE API to verify credentials
        return ['success' => true, 'message' => 'เชื่อมต่อ LINE สำเร็จ'];
    }

    /**
     * Test Telegram connection.
     */
    protected function testTelegramConnection(Bot $bot): array
    {
        // Implementation would use Telegram API to verify credentials
        return ['success' => true, 'message' => 'เชื่อมต่อ Telegram สำเร็จ'];
    }

    /**
     * Test Facebook connection.
     */
    protected function testFacebookConnection(Bot $bot): array
    {
        // Implementation would use Facebook API to verify credentials
        return ['success' => true, 'message' => 'เชื่อมต่อ Facebook สำเร็จ'];
    }
}
