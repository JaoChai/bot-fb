---
id: line-005-rich-menu-issues
title: LINE Rich Menu Not Showing
impact: MEDIUM
impactDescription: "Users cannot access quick actions, degraded UX"
category: line
tags: [line, rich-menu, ui, configuration]
relatedRules: [line-003-flex-message-errors, line-004-profile-fetch-failure]
---

## Symptom

- Rich menu not visible in chat
- Menu shows for some users but not others
- Menu actions not triggering
- Wrong menu displayed

## Root Cause

1. Rich menu not linked to user
2. Menu not set as default
3. Image dimensions incorrect
4. Invalid action configuration
5. Menu deleted or expired

## Diagnosis

### Quick Check

```bash
# List all rich menus
curl -X GET "https://api.line.me/v2/bot/richmenu/list" \
  -H "Authorization: Bearer {ACCESS_TOKEN}"

# Get user's current rich menu
curl -X GET "https://api.line.me/v2/bot/user/{userId}/richmenu" \
  -H "Authorization: Bearer {ACCESS_TOKEN}"

# Get default rich menu
curl -X GET "https://api.line.me/v2/bot/user/all/richmenu" \
  -H "Authorization: Bearer {ACCESS_TOKEN}"
```

### Detailed Analysis

```sql
-- Check rich menu assignments
SELECT
    rm.id,
    rm.name,
    rm.is_default,
    COUNT(rma.user_id) as assigned_users
FROM rich_menus rm
LEFT JOIN rich_menu_assignments rma ON rm.id = rma.rich_menu_id
WHERE rm.bot_id = {bot_id}
GROUP BY rm.id;
```

## Solution

### Fix Steps

1. **Create Rich Menu Properly**
```php
// Rich menu dimensions must be exact
$richMenuObject = [
    'size' => [
        'width' => 2500,   // Required: 2500
        'height' => 1686,  // Or 843 for compact
    ],
    'selected' => false,
    'name' => 'Main Menu',
    'chatBarText' => 'Menu',
    'areas' => [
        [
            'bounds' => ['x' => 0, 'y' => 0, 'width' => 833, 'height' => 843],
            'action' => ['type' => 'message', 'text' => 'Help'],
        ],
        // ... more areas
    ],
];
```

2. **Set as Default**
```php
// Set default rich menu for all users
$this->lineClient->setDefaultRichMenuId($richMenuId);
```

3. **Link to Specific User**
```php
// Assign to specific user
$this->lineClient->linkRichMenuToUser($userId, $richMenuId);
```

### Code Example

```php
// Good: Complete rich menu setup
class RichMenuService
{
    public function createAndSetDefault(Bot $bot, array $config): string
    {
        // 1. Create rich menu
        $response = $this->client->createRichMenu([
            'size' => ['width' => 2500, 'height' => 1686],
            'selected' => true,
            'name' => $config['name'],
            'chatBarText' => $config['chatBarText'] ?? 'Menu',
            'areas' => $this->buildAreas($config['actions']),
        ]);

        $richMenuId = $response->getRichMenuId();

        // 2. Upload image
        $imagePath = $config['image_path'];
        $this->client->uploadRichMenuImage($richMenuId, $imagePath);

        // 3. Set as default
        $this->client->setDefaultRichMenuId($richMenuId);

        // 4. Store in database
        RichMenu::create([
            'bot_id' => $bot->id,
            'line_rich_menu_id' => $richMenuId,
            'name' => $config['name'],
            'is_default' => true,
        ]);

        return $richMenuId;
    }

    private function buildAreas(array $actions): array
    {
        // Calculate bounds for 6-button grid (2x3)
        $areas = [];
        $buttonWidth = 833;  // 2500 / 3
        $buttonHeight = 843; // 1686 / 2

        foreach ($actions as $i => $action) {
            $col = $i % 3;
            $row = floor($i / 3);

            $areas[] = [
                'bounds' => [
                    'x' => $col * $buttonWidth,
                    'y' => $row * $buttonHeight,
                    'width' => $buttonWidth,
                    'height' => $buttonHeight,
                ],
                'action' => $action,
            ];
        }

        return $areas;
    }
}
```

## Prevention

- Use exact dimensions (2500x1686 or 2500x843)
- Always upload image after creating menu
- Test with LINE app before deploying
- Store rich menu IDs in database
- Clean up old menus periodically

## Debug Commands

```bash
# Delete all rich menus and start fresh
curl -X GET "https://api.line.me/v2/bot/richmenu/list" \
  -H "Authorization: Bearer {ACCESS_TOKEN}" | \
  jq -r '.richmenus[].richMenuId' | \
  xargs -I {} curl -X DELETE "https://api.line.me/v2/bot/richmenu/{}" \
    -H "Authorization: Bearer {ACCESS_TOKEN}"

# Check image requirements
# - Size: 2500x1686 or 2500x843
# - Format: JPEG or PNG
# - Max file size: 1MB
```

## Project-Specific Notes

**BotFacebook Context:**
- Rich menus managed via `app/Services/Line/RichMenuService.php`
- Templates stored in `storage/app/rich-menus/`
- Each bot can have multiple rich menus
- Switch menus based on user state (e.g., logged in vs guest)
