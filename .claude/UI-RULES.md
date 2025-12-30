# UI Development Rules

## Workflow
```
Request → Search ui-ux-pro-max → shadcn/ui → Tailwind → Checklist → Implement
```

## ui-ux-pro-max Search
```bash
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "<keyword>" --domain style
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "<keyword>" --domain typography
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "<keyword>" --domain color
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "<keyword>" --domain ux
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "<keyword>" --stack react
```

## shadcn/ui
```bash
cd frontend && npx shadcn add [component-name]
```

Available: Button, Card, Dialog, Sheet, Input, Select, Tabs, Dropdown, Avatar, Badge, ScrollArea, Tooltip

## Pre-delivery Checklist
- [ ] Lucide icons only (no emoji)
- [ ] cursor-pointer on clickables
- [ ] Dark/Light mode contrast OK
- [ ] Responsive: 320px, 768px, 1024px, 1440px
- [ ] Hover states don't cause layout shift
