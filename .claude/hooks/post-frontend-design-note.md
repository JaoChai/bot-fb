---
event: PostToolUse
trigger:
  - tool: Write
condition: |
  (file_path matches "frontend/src/components/**/*.tsx" OR
   file_path matches "frontend/src/pages/**/*.tsx") AND
  user_mentioned_ui OR
  user_mentioned_component OR
  user_mentioned_form
---

# 🎨 Frontend Design Auto-Activation Notice

## What Happened
When you create React components (`.tsx` files), the system **automatically invokes `/frontend-design`** to ensure production-grade UI quality.

## What This Means
✅ **Auto-Applied**:
- shadcn/ui components selected
- Tailwind CSS v4 styling applied
- Responsive design included
- Accessibility standards followed
- Modern React 19 patterns used

## Your Role
You don't need to do anything extra. The `/frontend-design` skill automatically:
1. Polishes the UI component
2. Ensures consistent design system
3. Removes generic AI aesthetics
4. Makes it production-ready

## After Component is Created
Typical next steps:

### 1. Test the Component
Use Playwright MCP to test interactions:
```bash
# Will be available after components created
```

### 2. Code Review
Run when ready:
```bash
/code-review
```
This will check both the design quality and code logic.

### 3. Create Commit
When satisfied:
```bash
/commit
```

## If You Want to Refine Further
If the auto-generated UI doesn't match your vision:
- Describe what you want to adjust
- System will refine the design
- `/frontend-design` may re-activate to polish further

## Framework & Tools in Use
- **Framework**: React 19.2+ with React Compiler
- **Components**: shadcn/ui (220+ ready templates)
- **Styling**: Tailwind CSS v4
- **Forms**: React Hook Form + Zod
- **State**: Zustand (client), TanStack Query (server)

## No Additional Configuration Needed
The frontend-design skill is pre-configured for this project with:
- shadcn/ui as default component library
- Tailwind v4 as styling framework
- Project-specific color scheme (if defined)

## Learn More
See FRONTEND_SETUP.md (will be created in Phase 1B)
