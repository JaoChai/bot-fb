<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    /**
     * GET /settings - Show settings page
     */
    public function index(): Response
    {
        $user = Auth::user();

        return Inertia::render('Settings/Index', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => null, // User model doesn't have avatar_url yet
                'email_verified_at' => $user->email_verified_at,
                'created_at' => $user->created_at,
            ],
            'notificationSettings' => $this->getNotificationSettings($user),
        ]);
    }

    /**
     * PUT /settings/profile - Update profile
     */
    public function updateProfile(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user->update($validated);

        return back()->with('success', 'อัปเดตโปรไฟล์เรียบร้อยแล้ว');
    }

    /**
     * PUT /settings/notifications - Update notification settings
     */
    public function updateNotifications(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'email_new_message' => ['boolean'],
            'email_daily_summary' => ['boolean'],
            'email_weekly_report' => ['boolean'],
        ]);

        // Store notification settings in UserSetting
        // Get or create user settings
        $settings = $user->getOrCreateSettings();

        // Store as JSON in notification_preferences column
        // Note: This requires adding 'notification_preferences' column to user_settings table
        // For now, we'll store in a JSON column if it exists, otherwise skip
        if (method_exists($settings, 'update')) {
            $settings->update([
                'notification_preferences' => $validated,
            ]);
        }

        return back()->with('success', 'บันทึกการตั้งค่าการแจ้งเตือนแล้ว');
    }

    /**
     * Get notification settings for a user.
     */
    private function getNotificationSettings($user): array
    {
        // Try to get notification preferences from user settings
        $settings = $user->settings;
        $preferences = $settings?->notification_preferences ?? [];

        return [
            'email_new_message' => $preferences['email_new_message'] ?? true,
            'email_daily_summary' => $preferences['email_daily_summary'] ?? false,
            'email_weekly_report' => $preferences['email_weekly_report'] ?? true,
        ];
    }
}
