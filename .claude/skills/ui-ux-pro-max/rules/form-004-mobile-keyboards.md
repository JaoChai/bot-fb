---
id: form-004-mobile-keyboards
title: Show Correct Mobile Keyboard
impact: MEDIUM
impactDescription: "Wrong keyboard type slows input and frustrates mobile users"
category: form
tags: [mobile, keyboard, inputmode, forms]
relatedRules: [responsive-001-touch-targets]
platforms: [Mobile]
---

## Why This Matters

Mobile users spend significant time entering data. Showing a numeric keyboard for phone numbers or an email keyboard with @ symbol visible makes input faster and reduces errors.

## The Problem

```html
<!-- Bad: Default text keyboard for numbers -->
<input type="text" placeholder="Phone number" />
<!-- User sees QWERTY, has to switch to numbers -->

<!-- Bad: Missing email keyboard hints -->
<input type="text" placeholder="Email" />
<!-- No @ symbol readily visible -->
```

## Solution

### Input Types

```html
<!-- Good: Use semantic input types -->
<input type="email" />    <!-- Shows @ and .com -->
<input type="tel" />      <!-- Shows phone dialpad -->
<input type="url" />      <!-- Shows / and .com -->
<input type="number" />   <!-- Shows numeric keyboard -->
<input type="search" />   <!-- Shows search button -->
```

### Inputmode Attribute (More Control)

```html
<!-- Good: Numeric keypad (no + or -) -->
<input type="text" inputmode="numeric" placeholder="PIN" />

<!-- Good: Decimal numbers -->
<input type="text" inputmode="decimal" placeholder="Price" />

<!-- Good: Email with validation flexibility -->
<input type="text" inputmode="email" placeholder="Email" />

<!-- Good: URL without type validation -->
<input type="text" inputmode="url" placeholder="Website" />

<!-- Good: Phone without type restrictions -->
<input type="text" inputmode="tel" placeholder="Phone" />
```

## Quick Reference

| Input | Type | Inputmode | Keyboard |
|-------|------|-----------|----------|
| Email | `email` | - | @ visible, .com |
| Phone | `tel` | - | Phone dialpad |
| Numbers only | `text` | `numeric` | 0-9 only |
| Decimal | `text` | `decimal` | 0-9 with . |
| URL | `url` | - | / and .com |
| Search | `search` | - | Search button |
| Credit card | `text` | `numeric` | 0-9 only |
| PIN/OTP | `text` | `numeric` | 0-9 only |

## Inputmode Values

| Value | Keyboard | Use For |
|-------|----------|---------|
| `text` | Default QWERTY | Names, general text |
| `numeric` | 0-9 only | PIN, OTP, quantity |
| `decimal` | 0-9 with period | Prices, measurements |
| `tel` | Phone dialpad | Phone numbers |
| `email` | @ and .com visible | Email addresses |
| `url` | / and .com visible | URLs |
| `search` | Search action key | Search fields |

## React Pattern

```tsx
interface InputProps {
  type?: 'text' | 'email' | 'tel' | 'url' | 'search';
  inputMode?: 'text' | 'numeric' | 'decimal' | 'tel' | 'email' | 'url' | 'search';
}

// Good: Props for common input types
<input
  type="text"
  inputMode="numeric"
  pattern="[0-9]*"
  placeholder="Enter PIN"
/>

// Good: Credit card input
<input
  type="text"
  inputMode="numeric"
  maxLength={16}
  placeholder="Card number"
  autoComplete="cc-number"
/>

// Good: OTP input
<input
  type="text"
  inputMode="numeric"
  maxLength={6}
  pattern="[0-9]{6}"
  autoComplete="one-time-code"
/>
```

## Autocomplete for Common Fields

```html
<!-- Good: Help browser autofill -->
<input type="email" autocomplete="email" />
<input type="tel" autocomplete="tel" />
<input type="text" inputmode="numeric" autocomplete="cc-number" />
<input type="text" autocomplete="one-time-code" /> <!-- OTP -->
<input type="text" autocomplete="name" />
<input type="text" autocomplete="street-address" />
```

## Testing

- [ ] Test on iOS and Android devices
- [ ] Email field shows @ keyboard
- [ ] Phone field shows number pad
- [ ] OTP/PIN shows numeric keyboard
- [ ] Autofill works for common fields

## Project-Specific Notes

**BotFacebook Context:**
- Phone inputs: `type="tel"`
- API key inputs: `type="text"` (not password, needs copy)
- OTP verification: `inputmode="numeric" maxLength={6}`
- Search: `type="search"` for keyboard search button
