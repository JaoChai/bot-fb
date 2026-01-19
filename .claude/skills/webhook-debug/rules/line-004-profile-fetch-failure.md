---
id: line-004-profile-fetch-failure
title: LINE User Profile Fetch Fails
impact: MEDIUM
impactDescription: "Cannot personalize messages or show user info"
category: line
tags: [line, profile, api, user-data]
relatedRules: [line-001-signature-validation, flow-003-error-handling]
---

## Symptom

- User profile returns null
- Display name shows "Unknown User"
- Profile picture not loading
- Error: "Failed to get profile" or 404

## Root Cause

1. User blocked the bot
2. User left the chat
3. Invalid user ID format
4. Bot not added to user's friend list
5. Token permissions insufficient

## Diagnosis

### Quick Check

```bash
# Test profile API directly
curl -X GET "https://api.line.me/v2/bot/profile/{userId}" \
  -H "Authorization: Bearer {ACCESS_TOKEN}"

# Check for profile errors in logs
railway logs --filter "profile"
```

### Detailed Analysis

```sql
-- Check stored profiles and their freshness
SELECT
    line_user_id,
    display_name,
    picture_url,
    updated_at,
    NOW() - updated_at as age
FROM line_profiles
WHERE bot_id = {bot_id}
ORDER BY updated_at DESC
LIMIT 10;
```

## Solution

### Fix Steps

1. **Handle Missing Profile Gracefully**
```php
public function getProfile(string $userId): ?LineProfile
{
    try {
        $response = $this->client->getProfile($userId);
        return new LineProfile($response->getJSONDecodedBody());
    } catch (LINEBotException $e) {
        if ($e->getHTTPStatus() === 404) {
            // User blocked bot or not friend
            return null;
        }
        throw $e;
    }
}
```

2. **Cache Profiles**
```php
public function getProfileCached(string $userId): ?LineProfile
{
    return Cache::remember(
        "line_profile:{$userId}",
        now()->addHours(24),
        fn() => $this->getProfile($userId)
    );
}
```

3. **Use Fallback Display Name**
```php
$displayName = $profile?->displayName ?? 'User ' . substr($userId, -4);
```

### Code Example

```php
// Good: Robust profile handling
class LineProfileService
{
    public function getOrFetchProfile(string $userId, Bot $bot): LineProfile
    {
        // Try cache first
        $cached = LineProfile::where('line_user_id', $userId)
            ->where('updated_at', '>', now()->subDay())
            ->first();

        if ($cached) {
            return $cached;
        }

        // Fetch from API
        try {
            $response = $this->lineService->getProfile($userId);

            return LineProfile::updateOrCreate(
                ['line_user_id' => $userId, 'bot_id' => $bot->id],
                [
                    'display_name' => $response['displayName'],
                    'picture_url' => $response['pictureUrl'] ?? null,
                    'status_message' => $response['statusMessage'] ?? null,
                ]
            );
        } catch (LINEBotException $e) {
            // Return stub profile
            return new LineProfile([
                'line_user_id' => $userId,
                'display_name' => 'LINE User',
                'picture_url' => null,
            ]);
        }
    }
}
```

## Prevention

- Cache profiles for 24 hours minimum
- Handle 404 gracefully (don't crash)
- Update profile on follow event
- Clear cache when user re-follows
- Use background job for profile refresh

## Debug Commands

```bash
# Check if user is friend
curl -X GET "https://api.line.me/v2/bot/followers/ids" \
  -H "Authorization: Bearer {ACCESS_TOKEN}"

# Get specific profile
curl -X GET "https://api.line.me/v2/bot/profile/U1234567890abcdef" \
  -H "Authorization: Bearer {ACCESS_TOKEN}"
```

## Project-Specific Notes

**BotFacebook Context:**
- Profiles cached in `line_profiles` table
- Profile fetched on `follow` event
- `LineProfileService::getOrCreate()` handles fetching
- Picture URLs may expire (LINE rotates CDN URLs)
