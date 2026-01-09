<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Create admin user
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@botfacebook.test',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'subscription_plan' => 'pro',
            'subscription_expires_at' => now()->addYear(),
            'timezone' => 'Asia/Bangkok',
        ]);

        // Create demo user
        User::create([
            'name' => 'Demo User',
            'email' => 'demo@botfacebook.test',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'subscription_plan' => 'free',
            'timezone' => 'Asia/Bangkok',
        ]);
    }
}
