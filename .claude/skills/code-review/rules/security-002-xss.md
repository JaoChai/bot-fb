---
id: security-002-xss
title: XSS (Cross-Site Scripting) Prevention
impact: CRITICAL
impactDescription: "Attackers can execute malicious scripts in user browsers"
category: security
tags: [security, xss, owasp, frontend]
relatedRules: [frontend-001-component-size]
---

## Why This Matters

XSS allows attackers to inject malicious scripts that run in victims' browsers, stealing sessions, credentials, or performing actions as the user.

## Bad Example

```tsx
// React: dangerouslySetInnerHTML without sanitization
function Comment({ html }: { html: string }) {
  return <div dangerouslySetInnerHTML={{ __html: html }} />;
}

// Blade: Unescaped output
<div>{!! $userContent !!}</div>

// URL in href without validation
<a href={userUrl}>Click here</a>
```

**Why it's wrong:**
- Raw HTML from user input
- No content sanitization
- `javascript:` URLs can execute code

## Good Example

```tsx
// React: Use DOMPurify for HTML content
import DOMPurify from 'dompurify';

function Comment({ html }: { html: string }) {
  const sanitized = DOMPurify.sanitize(html);
  return <div dangerouslySetInnerHTML={{ __html: sanitized }} />;
}

// Better: Render as text (auto-escaped)
function Comment({ content }: { content: string }) {
  return <div>{content}</div>;
}

// Blade: Default escaped output
<div>{{ $userContent }}</div>

// URL validation
const isValidUrl = (url: string) => {
  try {
    const parsed = new URL(url);
    return ['http:', 'https:'].includes(parsed.protocol);
  } catch {
    return false;
  }
};
```

**Why it's better:**
- Content sanitized before rendering
- HTML entities escaped
- URLs validated against javascript: protocol

## Review Checklist

- [ ] No `dangerouslySetInnerHTML` without DOMPurify
- [ ] No `{!! !!}` with user content in Blade
- [ ] URLs validated before use in href/src
- [ ] User content rendered as text, not HTML
- [ ] API responses don't include raw HTML

## Detection

```bash
# React XSS patterns
grep -rn "dangerouslySetInnerHTML" --include="*.tsx" --include="*.jsx" src/

# Blade unescaped
grep -rn "{!! \$" --include="*.blade.php" resources/

# URL in href without check
grep -rn "href={" --include="*.tsx" src/ | grep -v "href=\""
```

## Project-Specific Notes

**BotFacebook XSS Prevention:**

```tsx
// Message display - always escaped
function MessageBubble({ content }: { content: string }) {
  return (
    <div className="message-bubble">
      {content} {/* Auto-escaped */}
    </div>
  );
}

// If markdown needed, use react-markdown (sanitizes by default)
import ReactMarkdown from 'react-markdown';

function FormattedMessage({ content }: { content: string }) {
  return <ReactMarkdown>{content}</ReactMarkdown>;
}
```
