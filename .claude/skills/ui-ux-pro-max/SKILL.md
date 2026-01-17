---
name: ui-ux-pro-max
description: UI/UX design intelligence with 50 styles, 21 color palettes, 50 font pairings. Use when designing websites, landing pages, dashboards, or any UI component. Includes accessibility and responsive design guidelines.
---

# UI/UX Pro Max - Design Intelligence

Searchable database of UI styles, color palettes, font pairings, chart types, product recommendations, UX guidelines, and stack-specific best practices.

## MCP Tools Available

- **context7**: `query-docs` - Get latest Tailwind CSS, React, CSS documentation

## Quick Start

When user requests UI/UX work (design, build, create, implement, review, fix, improve), follow this workflow:

### Step 1: Analyze User Requirements

Extract key information from user request:
- **Product type**: SaaS, e-commerce, portfolio, dashboard, landing page, etc.
- **Style keywords**: minimal, playful, professional, elegant, dark mode, etc.
- **Industry**: healthcare, fintech, gaming, education, etc.
- **Stack**: React, Vue, Next.js, or default to `html-tailwind`

### Step 2: Search Relevant Domains

Use `search.py` multiple times to gather comprehensive information. Search until you have enough context.

```bash
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "<keyword>" --domain <domain> [-n <max_results>]
```

**Recommended search order:**

1. **Product** - Get style recommendations for product type
2. **Style** - Get detailed style guide (colors, effects, frameworks)
3. **Typography** - Get font pairings with Google Fonts imports
4. **Color** - Get color palette (Primary, Secondary, CTA, Background, Text, Border)
5. **Landing** - Get page structure (if landing page)
6. **Chart** - Get chart recommendations (if dashboard/analytics)
7. **UX** - Get best practices and anti-patterns
8. **Stack** - Get stack-specific guidelines (default: html-tailwind)

### Step 3: Stack Guidelines (Default: html-tailwind)

If user doesn't specify a stack, **default to `html-tailwind`**.

```bash
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "<keyword>" --stack html-tailwind
```

Available stacks: `html-tailwind`, `react`, `nextjs`, `vue`, `svelte`, `swiftui`, `react-native`, `flutter`

---

## Search Reference

### Available Domains

| Domain | Use For | Example Keywords |
|--------|---------|------------------|
| `product` | Product type recommendations | SaaS, e-commerce, portfolio, healthcare, beauty, service |
| `style` | UI styles, colors, effects | glassmorphism, minimalism, dark mode, brutalism |
| `typography` | Font pairings, Google Fonts | elegant, playful, professional, modern |
| `color` | Color palettes by product type | saas, ecommerce, healthcare, beauty, fintech, service |
| `landing` | Page structure, CTA strategies | hero, hero-centric, testimonial, pricing, social-proof |
| `chart` | Chart types, library recommendations | trend, comparison, timeline, funnel, pie |
| `ux` | Best practices, anti-patterns | animation, accessibility, z-index, loading |
| `prompt` | AI prompts, CSS keywords | (style name) |

### Available Stacks

| Stack | Focus |
|-------|-------|
| `html-tailwind` | Tailwind utilities, responsive, a11y (DEFAULT) |
| `react` | State, hooks, performance, patterns |
| `nextjs` | SSR, routing, images, API routes |
| `vue` | Composition API, Pinia, Vue Router |
| `svelte` | Runes, stores, SvelteKit |
| `swiftui` | Views, State, Navigation, Animation |
| `react-native` | Components, Navigation, Lists |
| `flutter` | Widgets, State, Layout, Theming |

---

## Example Workflow

**User request:** "Làm landing page cho dịch vụ chăm sóc da chuyên nghiệp"

**AI should:**

```bash
# 1. Search product type
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "beauty spa wellness service" --domain product

# 2. Search style (based on industry: beauty, elegant)
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "elegant minimal soft" --domain style

# 3. Search typography
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "elegant luxury" --domain typography

# 4. Search color palette
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "beauty spa wellness" --domain color

# 5. Search landing page structure
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "hero-centric social-proof" --domain landing

# 6. Search UX guidelines
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "animation" --domain ux
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "accessibility" --domain ux

# 7. Search stack guidelines (default: html-tailwind)
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "layout responsive" --stack html-tailwind
```

**Then:** Synthesize all search results and implement the design.

---

## Tips for Better Results

1. **Be specific with keywords** - "healthcare SaaS dashboard" > "app"
2. **Search multiple times** - Different keywords reveal different insights
3. **Combine domains** - Style + Typography + Color = Complete design system
4. **Always check UX** - Search "animation", "z-index", "accessibility" for common issues
5. **Use stack flag** - Get implementation-specific best practices
6. **Iterate** - If first search doesn't match, try different keywords

---

## Common Rules for Professional UI

These are frequently overlooked issues that make UI look unprofessional:

### Icons & Visual Elements

| Rule | Do | Don't |
|------|----|----- |
| **No emoji icons** | Use SVG icons (Heroicons, Lucide, Simple Icons) | Use emojis like 🎨 🚀 ⚙️ as UI icons |
| **Stable hover states** | Use color/opacity transitions on hover | Use scale transforms that shift layout |
| **Correct brand logos** | Research official SVG from Simple Icons | Guess or use incorrect logo paths |
| **Consistent icon sizing** | Use fixed viewBox (24x24) with w-6 h-6 | Mix different icon sizes randomly |

### Interaction & Cursor

| Rule | Do | Don't |
|------|----|----- |
| **Cursor pointer** | Add `cursor-pointer` to all clickable/hoverable cards | Leave default cursor on interactive elements |
| **Hover feedback** | Provide visual feedback (color, shadow, border) | No indication element is interactive |
| **Smooth transitions** | Use `transition-colors duration-200` | Instant state changes or too slow (>500ms) |

### Light/Dark Mode Contrast

| Rule | Do | Don't |
|------|----|----- |
| **Glass card light mode** | Use `bg-white/80` or higher opacity | Use `bg-white/10` (too transparent) |
| **Text contrast light** | Use `#0F172A` (slate-900) for text | Use `#94A3B8` (slate-400) for body text |
| **Muted text light** | Use `#475569` (slate-600) minimum | Use gray-400 or lighter |
| **Border visibility** | Use `border-gray-200` in light mode | Use `border-white/10` (invisible) |

### Layout & Spacing

| Rule | Do | Don't |
|------|----|----- |
| **Floating navbar** | Add `top-4 left-4 right-4` spacing | Stick navbar to `top-0 left-0 right-0` |
| **Content padding** | Account for fixed navbar height | Let content hide behind fixed elements |
| **Consistent max-width** | Use same `max-w-6xl` or `max-w-7xl` | Mix different container widths |

---

## Pre-Delivery Checklist

Before delivering UI code, verify these items:

### Visual Quality
- [ ] No emojis used as icons (use SVG instead)
- [ ] All icons from consistent icon set (Heroicons/Lucide)
- [ ] Brand logos are correct (verified from Simple Icons)
- [ ] Hover states don't cause layout shift
- [ ] Use theme colors directly (bg-primary) not var() wrapper

### Interaction
- [ ] All clickable elements have `cursor-pointer`
- [ ] Hover states provide clear visual feedback
- [ ] Transitions are smooth (150-300ms)
- [ ] Focus states visible for keyboard navigation

### Light/Dark Mode
- [ ] Light mode text has sufficient contrast (4.5:1 minimum)
- [ ] Glass/transparent elements visible in light mode
- [ ] Borders visible in both modes
- [ ] Test both modes before delivery

### Layout
- [ ] Floating elements have proper spacing from edges
- [ ] No content hidden behind fixed navbars
- [ ] Responsive at 320px, 768px, 1024px, 1440px
- [ ] No horizontal scroll on mobile

### Accessibility
- [ ] All images have alt text
- [ ] Form inputs have labels
- [ ] Color is not the only indicator
- [ ] `prefers-reduced-motion` respected

---

## Common Tasks

### 1. Design New Landing Page

```markdown
1. Search product type → Get industry recommendations
2. Search style → Choose visual direction
3. Search typography → Pick font pairing
4. Search color → Define color palette
5. Search landing → Get page structure
6. Search UX → Check best practices
7. Implement with stack guidelines
8. Run pre-delivery checklist
```

### 2. Design Dashboard

```markdown
1. Search "dashboard" in product domain
2. Search style (data-heavy = minimal, clean)
3. Search chart types needed
4. Search color (accessible, distinguishable)
5. Define component library
6. Implement responsive grid
7. Test with real data
```

### 3. Improve Existing UI

```markdown
1. Identify issues (contrast, spacing, consistency)
2. Search style for improvements
3. Search UX for anti-patterns to fix
4. Apply changes incrementally
5. Run pre-delivery checklist
6. A/B test if possible
```

### 4. Create Design System

```markdown
1. Define color palette (search color domain)
2. Define typography scale (search typography)
3. Define spacing scale (4px, 8px, 12px, 16px...)
4. Create component variants (buttons, inputs, cards)
5. Document in style guide
6. Implement as Tailwind config
```

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

- **Styles Database**: See [data/styles.json](data/styles.json)
- **Color Palettes**: See [data/colors.json](data/colors.json)
- **Typography**: See [data/typography.json](data/typography.json)
- **UX Guidelines**: See [data/ux.json](data/ux.json)

## Design Output Format

```markdown
## Design Specification: [Component/Page Name]

### Style
- **Theme**: [e.g., Glassmorphism, Minimalism]
- **Mood**: [e.g., Professional, Playful]

### Colors
- **Primary**: #XXXXXX
- **Secondary**: #XXXXXX
- **Background**: #XXXXXX
- **Text**: #XXXXXX

### Typography
- **Heading**: [Font Name] - [Weight]
- **Body**: [Font Name] - [Weight]
- **Import**: `@import url('...')`

### Components
- [List of components to implement]

### Responsive Breakpoints
- Mobile: 320px
- Tablet: 768px
- Desktop: 1024px
- Wide: 1440px
```

---

## Prerequisites

Check if Python is installed (required for search scripts):

```bash
python3 --version || python --version
```

If Python is not installed:

**macOS:** `brew install python3`
**Ubuntu/Debian:** `sudo apt update && sudo apt install python3`
**Windows:** `winget install Python.Python.3.12`
