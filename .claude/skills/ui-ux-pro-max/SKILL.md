---
name: ui-ux-pro-max
description: |
  UI/UX design intelligence for websites, landing pages, dashboards.
  Triggers: 'design', 'ui', 'ux', 'landing page', 'color palette', 'font pairing', 'shadcn', 'tailwind styling'.
  Use when: creating new UI, choosing colors/fonts, implementing design systems, reviewing visual quality.
allowed-tools:
  - Bash(python3 *.py*)
  - Read
  - Grep
  - WebFetch
context:
  - path: frontend/tailwind.config.ts
  - path: frontend/src/index.css
  - path: frontend/src/components/ui/
---

# UI/UX Pro Max - Design Intelligence

Searchable database of UI styles, color palettes, font pairings, chart types, product recommendations, UX guidelines, and stack-specific best practices.

## MCP Tools Available

- **context7**: `query-docs` - Get latest Tailwind CSS, React, CSS documentation

## Quick Start

When user requests UI/UX work (design, build, create, implement, review, fix, improve):

### Step 1: Analyze User Requirements

Extract key information:
- **Product type**: SaaS, e-commerce, portfolio, dashboard, landing page
- **Style keywords**: minimal, playful, professional, elegant, dark mode
- **Industry**: healthcare, fintech, gaming, education
- **Stack**: React, Vue, Next.js, or default to `html-tailwind`

### Step 2: Search Relevant Domains

```bash
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "<keyword>" --domain <domain> [-n <max_results>]
```

**Search order:**
1. **Product** - Style recommendations for product type
2. **Style** - Detailed style guide (colors, effects, frameworks)
3. **Typography** - Font pairings with Google Fonts imports
4. **Color** - Color palette (Primary, Secondary, CTA, Background, Text, Border)
5. **Landing** - Page structure (if landing page)
6. **Chart** - Chart recommendations (if dashboard/analytics)
7. **UX** - Best practices and anti-patterns
8. **Stack** - Stack-specific guidelines (default: html-tailwind)

## Search Reference

### Available Domains

| Domain | Use For | Example Keywords |
|--------|---------|------------------|
| `product` | Product type recommendations | SaaS, e-commerce, portfolio |
| `style` | UI styles, colors, effects | glassmorphism, minimalism, dark mode |
| `typography` | Font pairings, Google Fonts | elegant, playful, professional |
| `color` | Color palettes by product type | saas, ecommerce, healthcare |
| `landing` | Page structure, CTA strategies | hero, testimonial, pricing |
| `chart` | Chart types, library recommendations | trend, comparison, funnel |
| `ux` | Best practices, anti-patterns | animation, accessibility, z-index |

### Available Stacks

| Stack | Focus |
|-------|-------|
| `html-tailwind` | Tailwind utilities, responsive, a11y (DEFAULT) |
| `react` | State, hooks, performance, patterns |
| `nextjs` | SSR, routing, images, API routes |
| `vue` | Composition API, Pinia, Vue Router |

## Critical Rules (Check First)

| Rule | Impact | Issue |
|------|--------|-------|
| [icons-001](rules/icons-001-no-emoji.md) | CRITICAL | No emoji icons - use SVG |
| [contrast-001](rules/contrast-001-light-mode.md) | CRITICAL | Light mode contrast issues |
| [interaction-001](rules/interaction-001-cursor-pointer.md) | HIGH | Missing cursor-pointer |
| [layout-001](rules/layout-001-floating-navbar.md) | HIGH | Floating navbar spacing |

## Key Files

### Project Files

| File | Purpose |
|------|---------|
| `frontend/tailwind.config.ts` | Theme configuration |
| `frontend/src/index.css` | Global styles |
| `frontend/src/components/ui/` | UI primitives (Radix) |
| `frontend/src/lib/utils.ts` | cn() utility |

### Skill Data Files

| File | Purpose |
|------|---------|
| `data/styles.json` | 50 UI styles database |
| `data/colors.json` | 21 color palettes |
| `data/typography.json` | 50 font pairings |
| `data/landing.json` | Landing page structures |
| `data/charts.json` | Chart type recommendations |
| `data/ux.json` | UX best practices |

## Detailed Guides

- **shadcn/ui Components**: See [SHADCN_GUIDE.md](SHADCN_GUIDE.md)
- **Design Templates**: See [DESIGN_TEMPLATES.md](DESIGN_TEMPLATES.md)
- **Pre-Delivery Checklist**: See [CHECKLIST.md](CHECKLIST.md)
- **Styles Database**: See [data/styles.json](data/styles.json)
- **Color Palettes**: See [data/colors.json](data/colors.json)

## Tips for Better Results

1. **Be specific with keywords** - "healthcare SaaS dashboard" > "app"
2. **Search multiple times** - Different keywords reveal different insights
3. **Combine domains** - Style + Typography + Color = Complete design system
4. **Always check UX** - Search "animation", "accessibility" for common issues
5. **Use stack flag** - Get implementation-specific best practices

## Prerequisites

```bash
python3 --version || python --version
```

If Python not installed: `brew install python3` (macOS)
