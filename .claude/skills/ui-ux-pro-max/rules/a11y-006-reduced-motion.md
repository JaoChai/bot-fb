---
id: a11y-006-reduced-motion
title: Respect prefers-reduced-motion
impact: HIGH
impactDescription: "Animations can trigger vestibular disorders, causing nausea and dizziness"
category: a11y
tags: [motion, animation, vestibular, accessibility]
relatedRules: [ux-002-animation-timing, ux-004-animation-performance]
platforms: [All]
---

## Why This Matters

~35% of adults over 40 experience vestibular dysfunction. Animations—especially parallax, zooming, and bouncing—can cause physical discomfort, nausea, and migraines. Users can set "reduce motion" in their OS settings.

## The Problem

```css
/* Bad: No motion preference check */
.hero {
  animation: parallax 10s infinite;
}

.button:hover {
  animation: bounce 500ms;
}
```

## Solution

### CSS Media Query

```css
/* Good: Respect user preference */
@media (prefers-reduced-motion: reduce) {
  *,
  *::before,
  *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
  }
}

/* Or disable specific animations */
.hero {
  animation: parallax 10s infinite;
}

@media (prefers-reduced-motion: reduce) {
  .hero {
    animation: none;
  }
}
```

### Tailwind Motion-Safe/Reduce

```html
<!-- Animation only when motion is OK -->
<div class="motion-safe:animate-bounce">
  Bounces only if user allows motion
</div>

<!-- Alternative for reduced motion -->
<div class="
  motion-safe:transition-transform motion-safe:hover:scale-105
  motion-reduce:opacity-80 motion-reduce:hover:opacity-100
">
  Transform when OK, opacity change when reduced
</div>
```

### JavaScript Check

```tsx
// Good: Check motion preference in code
function AnimatedComponent() {
  const prefersReducedMotion = window.matchMedia(
    '(prefers-reduced-motion: reduce)'
  ).matches;

  return (
    <motion.div
      animate={prefersReducedMotion
        ? { opacity: 1 }
        : { opacity: 1, y: 0 }
      }
      initial={prefersReducedMotion
        ? { opacity: 0 }
        : { opacity: 0, y: 20 }
      }
    >
      Content
    </motion.div>
  );
}
```

### React Hook

```tsx
function usePrefersReducedMotion() {
  const [prefersReducedMotion, setPrefersReducedMotion] = useState(false);

  useEffect(() => {
    const mediaQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
    setPrefersReducedMotion(mediaQuery.matches);

    const handler = (e: MediaQueryListEvent) => setPrefersReducedMotion(e.matches);
    mediaQuery.addEventListener('change', handler);
    return () => mediaQuery.removeEventListener('change', handler);
  }, []);

  return prefersReducedMotion;
}
```

## Quick Reference

| Animation Type | Reduced Motion Alternative |
|---------------|---------------------------|
| Parallax scroll | Static background |
| Bouncing elements | Instant state change |
| Sliding transitions | Fade or instant |
| Auto-playing video | Pause by default |
| Infinite animations | Static or single run |

## Tailwind Classes

```
motion-safe:*    - Apply only when motion is OK
motion-reduce:*  - Apply when reduced motion preferred
```

## Testing

1. Enable reduced motion:
   - **macOS**: System Preferences → Accessibility → Display → Reduce motion
   - **Windows**: Settings → Ease of Access → Display → Show animations
   - **iOS**: Settings → Accessibility → Motion → Reduce Motion
2. Refresh page
3. Verify animations are minimized/removed

## Project-Specific Notes

**BotFacebook Context:**
- Add global reduced motion styles in `index.css`
- Use `motion-safe:` prefix for decorative animations
- Essential animations (loading) can still animate
- Framer Motion: check `prefersReducedMotion` hook
