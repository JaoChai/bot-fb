<?php

namespace Database\Seeders;

use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\User;
use Illuminate\Database\Seeder;

class BotSeeder extends Seeder
{
    public function run(): void
    {
        $adminUser = User::where('email', 'admin@botfacebook.test')->first();

        if (! $adminUser) {
            return;
        }

        // Create demo bot
        $demoBot = Bot::create([
            'user_id' => $adminUser->id,
            'name' => 'Demo Bot',
            'description' => 'A demo chatbot for testing',
            'status' => 'active',
            'channel_type' => 'demo',
        ]);

        // Create bot settings
        BotSetting::create([
            'bot_id' => $demoBot->id,
            'daily_message_limit' => 1000,
            'per_user_limit' => 100,
            'hitl_enabled' => false,
            'welcome_message' => 'สวัสดีครับ! ผมเป็น Demo Bot ยินดีให้บริการครับ 🤖',
            'fallback_message' => 'ขออภัยครับ ผมไม่เข้าใจคำถาม กรุณาลองถามใหม่อีกครั้งนะครับ',
            'typing_indicator' => true,
            'typing_delay_ms' => 1000,
        ]);

        // Create LINE bot (inactive - for testing)
        $lineBot = Bot::create([
            'user_id' => $adminUser->id,
            'name' => 'LINE Bot',
            'description' => 'LINE OA chatbot',
            'status' => 'inactive',
            'channel_type' => 'line',
        ]);

        BotSetting::create([
            'bot_id' => $lineBot->id,
            'daily_message_limit' => 500,
            'per_user_limit' => 50,
            'response_hours_enabled' => true,
            'response_hours' => [
                'mon' => ['start' => '09:00', 'end' => '18:00'],
                'tue' => ['start' => '09:00', 'end' => '18:00'],
                'wed' => ['start' => '09:00', 'end' => '18:00'],
                'thu' => ['start' => '09:00', 'end' => '18:00'],
                'fri' => ['start' => '09:00', 'end' => '18:00'],
            ],
            'offline_message' => 'ขณะนี้อยู่นอกเวลาทำการ กรุณาติดต่อใหม่ในเวลา 09:00-18:00 น. วันจันทร์-ศุกร์ครับ',
        ]);
    }
}
