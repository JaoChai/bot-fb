---
event: PostToolUse
trigger: Edit|Write
condition: "file matches frontend/**/*.tsx or frontend/**/*.jsx or **/*.html"
---

# UI/UX Pro Max Auto-Activation Hook

## When This Hook Triggers
This hook activates after editing or creating frontend UI files.

## Auto-Suggestion
When you've just created or edited a UI component file, consider using the **UI/UX Pro Max** skill for professional design guidance.

## How to Invoke
The skill activates automatically when you request UI/UX work. Just describe what you want:

```
"สร้าง dashboard สำหรับ bot analytics"
"ออกแบบ chat interface ให้ดูโมเดิร์น"
"ทำ landing page สำหรับ chatbot service"
```

Or use the skill directly:
```
/ui-ux-pro-max
```

## What the Skill Provides
- **50 UI Styles**: Glassmorphism, Minimalism, Brutalism, Bento Grid, etc.
- **21 Color Palettes**: Industry-specific (SaaS, Healthcare, Fintech, etc.)
- **50 Font Pairings**: Google Fonts with import codes
- **20 Chart Types**: For dashboards and analytics
- **8 Tech Stacks**: React, Next.js, Vue, Svelte, SwiftUI, Flutter, etc.

## Stack Default
For this project, we use **React + Tailwind** (stack: `react`).

## Quick Reference Commands
```bash
# Search for style inspiration
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "glassmorphism dashboard" --domain style

# Search for color palette
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "saas chatbot" --domain color

# Search for typography
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "modern professional" --domain typography

# Get React-specific guidelines
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "dashboard" --stack react
```

## Professional UI Checklist
Before delivering UI code, verify:
- [ ] No emojis as icons (use Lucide/Heroicons SVG)
- [ ] All clickable elements have `cursor-pointer`
- [ ] Hover states are smooth (150-300ms transitions)
- [ ] Light/dark mode contrast is sufficient
- [ ] Responsive at 320px, 768px, 1024px, 1440px
