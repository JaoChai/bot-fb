<?php

namespace Tests\Unit\Commands;

use App\Console\Commands\RefreshLineProfilePictures;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Services\LINEService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class RefreshLineProfilePicturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /**
     * Create a LINE bot with customer profile and conversation for testing.
     */
    protected function createLineSetup(array $profileData = []): array
    {
        $bot = Bot::factory()->create([
            'channel_type' => 'line',
            'channel_access_token' => 'test_access_token',
            'channel_secret' => 'test_secret',
        ]);

        $profile = CustomerProfile::create(array_merge([
            'external_id' => 'U123456789',
            'channel_type' => 'line',
            'display_name' => 'Test User',
            'picture_url' => 'https://old-cdn.line-scdn.net/old-picture.jpg',
            'last_interaction_at' => now()->subDays(5),
        ], $profileData));

        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $profile->id,
            'channel_type' => 'line',
            'external_customer_id' => $profile->external_id,
            'last_message_at' => now()->subDays(5),
        ]);

        return compact('bot', 'profile', 'conversation');
    }

    public function test_updates_profile_when_line_returns_valid_picture_url(): void
    {
        $setup = $this->createLineSetup();
        $newPictureUrl = 'https://new-cdn.line-scdn.net/new-picture.jpg';

        Http::fake([
            'api.line.me/v2/bot/profile/*' => Http::response([
                'userId' => $setup['profile']->external_id,
                'displayName' => 'Updated User',
                'pictureUrl' => $newPictureUrl,
                'statusMessage' => 'New status',
            ], 200),
        ]);

        $this->artisan('profiles:refresh-line-pictures', ['--days' => 30])
            ->assertSuccessful();

        $setup['profile']->refresh();

        $this->assertEquals($newPictureUrl, $setup['profile']->picture_url);
        $this->assertEquals('Updated User', $setup['profile']->display_name);
    }

    public function test_clears_picture_url_when_line_returns_null(): void
    {
        $setup = $this->createLineSetup([
            'picture_url' => 'https://old-expired.line-scdn.net/expired.jpg',
        ]);

        // LINE API returns profile without pictureUrl (user removed picture)
        Http::fake([
            'api.line.me/v2/bot/profile/*' => Http::response([
                'userId' => $setup['profile']->external_id,
                'displayName' => 'User Without Picture',
                'statusMessage' => 'Hello',
                // pictureUrl is missing
            ], 200),
        ]);

        $this->artisan('profiles:refresh-line-pictures', ['--days' => 30])
            ->assertSuccessful();

        $setup['profile']->refresh();

        // picture_url should be cleared to null
        $this->assertNull($setup['profile']->picture_url);
        // display_name should still be updated
        $this->assertEquals('User Without Picture', $setup['profile']->display_name);
    }

    public function test_clears_picture_url_when_line_returns_empty_string(): void
    {
        $setup = $this->createLineSetup([
            'picture_url' => 'https://old-expired.line-scdn.net/expired.jpg',
        ]);

        // LINE API returns empty pictureUrl
        Http::fake([
            'api.line.me/v2/bot/profile/*' => Http::response([
                'userId' => $setup['profile']->external_id,
                'displayName' => 'User With Empty Picture',
                'pictureUrl' => '', // Empty string
                'statusMessage' => 'Hello',
            ], 200),
        ]);

        $this->artisan('profiles:refresh-line-pictures', ['--days' => 30])
            ->assertSuccessful();

        $setup['profile']->refresh();

        // picture_url should be cleared to null
        $this->assertNull($setup['profile']->picture_url);
        $this->assertEquals('User With Empty Picture', $setup['profile']->display_name);
    }

    public function test_skips_profile_when_line_api_returns_empty_response(): void
    {
        $originalPictureUrl = 'https://original.line-scdn.net/picture.jpg';
        $setup = $this->createLineSetup([
            'picture_url' => $originalPictureUrl,
            'display_name' => 'Original Name',
        ]);

        // LINE API returns empty response (API failure)
        Http::fake([
            'api.line.me/v2/bot/profile/*' => Http::response([
                'userId' => $setup['profile']->external_id,
                'displayName' => null,
                'pictureUrl' => null,
                'statusMessage' => null,
            ], 200),
        ]);

        $this->artisan('profiles:refresh-line-pictures', ['--days' => 30])
            ->assertSuccessful();

        $setup['profile']->refresh();

        // Profile should not be modified when API returns empty data
        $this->assertEquals($originalPictureUrl, $setup['profile']->picture_url);
        $this->assertEquals('Original Name', $setup['profile']->display_name);
    }

    public function test_skips_profile_when_line_api_returns_404(): void
    {
        $originalPictureUrl = 'https://original.line-scdn.net/picture.jpg';
        $setup = $this->createLineSetup([
            'picture_url' => $originalPictureUrl,
            'display_name' => 'Original Name',
        ]);

        // LINE API returns 404 (user blocked bot)
        Http::fake([
            'api.line.me/v2/bot/profile/*' => Http::response([], 404),
        ]);

        $this->artisan('profiles:refresh-line-pictures', ['--days' => 30])
            ->assertSuccessful();

        $setup['profile']->refresh();

        // Profile should not be modified when API fails
        $this->assertEquals($originalPictureUrl, $setup['profile']->picture_url);
        $this->assertEquals('Original Name', $setup['profile']->display_name);
    }

    public function test_tracks_cleared_count_separately_from_updated(): void
    {
        // Create profile with picture that will be cleared
        $setup = $this->createLineSetup([
            'picture_url' => 'https://old.line-scdn.net/old.jpg',
        ]);

        Http::fake([
            'api.line.me/v2/bot/profile/*' => Http::response([
                'userId' => $setup['profile']->external_id,
                'displayName' => 'User',
                // No pictureUrl - will be cleared
            ], 200),
        ]);

        $this->artisan('profiles:refresh-line-pictures', ['--days' => 30])
            ->expectsOutputToContain('Cleared (expired URLs)')
            ->assertSuccessful();
    }

    public function test_dry_run_does_not_modify_profiles(): void
    {
        $originalPictureUrl = 'https://original.line-scdn.net/picture.jpg';
        $setup = $this->createLineSetup([
            'picture_url' => $originalPictureUrl,
        ]);

        // Even with new picture URL, dry run should not update
        Http::fake([
            'api.line.me/v2/bot/profile/*' => Http::response([
                'userId' => $setup['profile']->external_id,
                'displayName' => 'New Name',
                'pictureUrl' => 'https://new.line-scdn.net/new.jpg',
            ], 200),
        ]);

        $this->artisan('profiles:refresh-line-pictures', ['--days' => 30, '--dry-run' => true])
            ->expectsOutputToContain('DRY RUN MODE')
            ->assertSuccessful();

        $setup['profile']->refresh();

        // Profile should not be modified in dry run
        $this->assertEquals($originalPictureUrl, $setup['profile']->picture_url);
    }

    public function test_only_processes_profiles_within_days_option(): void
    {
        // Create recent profile (should be processed)
        $recentSetup = $this->createLineSetup([
            'external_id' => 'U_recent',
            'last_interaction_at' => now()->subDays(5),
        ]);

        // Create old profile (should be skipped based on days option)
        $oldProfile = CustomerProfile::create([
            'external_id' => 'U_old',
            'channel_type' => 'line',
            'display_name' => 'Old User',
            'picture_url' => 'https://old.line-scdn.net/old.jpg',
            'last_interaction_at' => now()->subDays(60), // 60 days ago
        ]);

        Conversation::factory()->create([
            'bot_id' => $recentSetup['bot']->id,
            'customer_profile_id' => $oldProfile->id,
            'channel_type' => 'line',
            'external_customer_id' => $oldProfile->external_id,
        ]);

        Http::fake([
            'api.line.me/v2/bot/profile/*' => Http::response([
                'userId' => 'U_recent',
                'displayName' => 'Updated Recent',
                'pictureUrl' => 'https://new.line-scdn.net/new.jpg',
            ], 200),
        ]);

        $this->artisan('profiles:refresh-line-pictures', ['--days' => 30])
            ->assertSuccessful();

        // Only recent profile should be updated
        $recentSetup['profile']->refresh();
        $oldProfile->refresh();

        $this->assertEquals('Updated Recent', $recentSetup['profile']->display_name);
        $this->assertEquals('Old User', $oldProfile->display_name); // Unchanged
    }
}
