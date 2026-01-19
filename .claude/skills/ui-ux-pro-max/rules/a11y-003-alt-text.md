---
id: a11y-003-alt-text
title: Images Need Alt Text
impact: CRITICAL
impactDescription: "Screen reader users hear nothing for images without alt text"
category: a11y
tags: [alt, images, screen-reader, wcag]
relatedRules: [a11y-005-aria-labels]
platforms: [All]
---

## Why This Matters

Screen readers read alt text to describe images. Without it, users hear "image" or nothing, missing important content. Required by WCAG 2.1 Level A.

## The Problem

```html
<!-- Bad: No alt attribute -->
<img src="/product.jpg" />

<!-- Bad: Empty alt for content image -->
<img src="/product.jpg" alt="" />

<!-- Bad: Useless alt text -->
<img src="/product.jpg" alt="image" />
<img src="/product.jpg" alt="photo.jpg" />
```

## Solution

### Content Images (Meaningful)

```html
<!-- Good: Descriptive alt text -->
<img
  src="/dog-park.jpg"
  alt="Golden retriever playing fetch in Central Park"
/>

<!-- Good: Product image -->
<img
  src="/product.jpg"
  alt="Blue wireless headphones with noise cancellation"
/>
```

### Decorative Images (No Content)

```html
<!-- Good: Empty alt for purely decorative -->
<img src="/decorative-wave.svg" alt="" role="presentation" />

<!-- Good: Background decorative -->
<div
  class="bg-cover"
  style="background-image: url(/pattern.svg)"
  aria-hidden="true"
/>
```

### Functional Images (Buttons/Links)

```html
<!-- Good: Describes the action -->
<button>
  <img src="/search-icon.svg" alt="Search" />
</button>

<!-- Better: Use aria-label on button -->
<button aria-label="Search">
  <SearchIcon aria-hidden="true" />
</button>
```

## Quick Reference

| Image Type | Alt Text |
|------------|----------|
| Product photo | Describe product appearance |
| Person/Avatar | Person's name or "User avatar" |
| Decorative | Empty (`alt=""`) |
| Icon in button | Describe action, or aria-label on button |
| Chart/Graph | Describe data or link to table |
| Logo | Company name |

## Alt Text Best Practices

```
DO:
✓ Be specific and concise (125 chars max)
✓ Describe content, not decoration
✓ Start with the subject
✓ Include text in the image

DON'T:
✗ Start with "Image of..." or "Picture of..."
✗ Repeat surrounding text
✗ Use file names as alt text
✗ Leave empty for content images
```

## React Pattern

```tsx
// Good: Required alt prop
interface ImageProps {
  src: string;
  alt: string; // Required!
}

function ProductImage({ src, alt }: ImageProps) {
  return <img src={src} alt={alt} loading="lazy" />;
}

// For decorative
function DecorativeImage({ src }: { src: string }) {
  return <img src={src} alt="" role="presentation" />;
}
```

## Testing

- [ ] All `<img>` tags have `alt` attribute
- [ ] Content images have descriptive alt
- [ ] Decorative images have `alt=""`
- [ ] Test with screen reader (VoiceOver, NVDA)

## Project-Specific Notes

**BotFacebook Context:**
- Avatar images: `alt={user.name}` or `alt="User avatar"`
- Bot icons: Include bot name
- Decorative backgrounds: `alt="" aria-hidden="true"`
- Charts: Provide text summary or data table
