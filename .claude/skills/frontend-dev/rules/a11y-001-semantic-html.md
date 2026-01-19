---
id: a11y-001-semantic-html
title: Semantic HTML Elements
impact: HIGH
impactDescription: "Enables screen readers and assistive technologies to understand page structure"
category: a11y
tags: [accessibility, html, semantic, screen-reader]
relatedRules: [a11y-002-keyboard-navigation]
---

## Why This Matters

Semantic HTML provides meaning to content. Screen readers use semantic elements to navigate pages - users can jump between headings, navigate landmarks, and understand content hierarchy. Using divs for everything makes pages inaccessible.

Good semantics also improve SEO and code readability.

## Bad Example

```tsx
// Problem 1: Div soup with no semantics
function Page() {
  return (
    <div className="page">
      <div className="top-bar">
        <div className="logo">BotFacebook</div>
        <div className="nav-items">
          <div onClick={goHome}>Home</div>
          <div onClick={goSettings}>Settings</div>
        </div>
      </div>
      <div className="main-area">
        <div className="side">...</div>
        <div className="content">
          <div className="title">Dashboard</div>
          <div className="section-name">Recent Activity</div>
        </div>
      </div>
      <div className="bottom">© 2024</div>
    </div>
  );
}

// Problem 2: Wrong heading order
function Article() {
  return (
    <div>
      <h4>Main Title</h4> {/* Should be h1 */}
      <h2>Subtitle</h2>
      <h1>Section</h1> {/* Wrong order! */}
    </div>
  );
}

// Problem 3: Clickable div instead of button
function Card({ onClick }) {
  return (
    <div onClick={onClick} className="cursor-pointer">
      Click me
    </div>
  );
}
```

**Why it's wrong:**
- Screen readers can't identify navigation, main content, or footer
- Clicking divs not announced as buttons
- Heading hierarchy is broken
- No keyboard focus on clickable divs

## Good Example

```tsx
// Solution 1: Use semantic landmarks
function Page() {
  return (
    <div className="page">
      <header className="border-b">
        <div className="logo">BotFacebook</div>
        <nav aria-label="Main navigation">
          <ul className="flex gap-4">
            <li><a href="/">Home</a></li>
            <li><a href="/settings">Settings</a></li>
          </ul>
        </nav>
      </header>

      <div className="flex">
        <aside aria-label="Sidebar">
          {/* Secondary navigation or filters */}
        </aside>

        <main>
          <h1>Dashboard</h1>
          <section aria-labelledby="recent-heading">
            <h2 id="recent-heading">Recent Activity</h2>
            {/* Section content */}
          </section>
        </main>
      </div>

      <footer>
        <p>© 2024 BotFacebook</p>
      </footer>
    </div>
  );
}

// Solution 2: Proper heading hierarchy
function Article() {
  return (
    <article>
      <h1>Main Title</h1>
      <p>Introduction paragraph...</p>

      <section>
        <h2>First Section</h2>
        <p>Content...</p>

        <h3>Subsection</h3>
        <p>More content...</p>
      </section>

      <section>
        <h2>Second Section</h2>
        <p>Content...</p>
      </section>
    </article>
  );
}

// Solution 3: Interactive elements
function Card({ onClick, title }) {
  return (
    <article className="rounded-lg border p-4">
      <h3>{title}</h3>
      <p>Card description...</p>
      <button onClick={onClick} className="mt-2">
        View Details
      </button>
    </article>
  );
}

// Or if entire card is clickable
function ClickableCard({ href, title }) {
  return (
    <article className="rounded-lg border p-4">
      <h3>
        <a href={href} className="after:absolute after:inset-0">
          {title}
        </a>
      </h3>
      <p>Card description...</p>
    </article>
  );
}

// Solution 4: Lists for repeated content
function BotList({ bots }) {
  return (
    <ul className="space-y-2">
      {bots.map((bot) => (
        <li key={bot.id}>
          <BotCard bot={bot} />
        </li>
      ))}
    </ul>
  );
}

// Solution 5: Tables for tabular data
function StatsTable({ stats }) {
  return (
    <table>
      <caption className="sr-only">Bot performance statistics</caption>
      <thead>
        <tr>
          <th scope="col">Bot Name</th>
          <th scope="col">Messages</th>
          <th scope="col">Response Rate</th>
        </tr>
      </thead>
      <tbody>
        {stats.map((stat) => (
          <tr key={stat.id}>
            <th scope="row">{stat.name}</th>
            <td>{stat.messages}</td>
            <td>{stat.responseRate}%</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}
```

**Why it's better:**
- Landmarks (`header`, `main`, `nav`, `aside`, `footer`) enable navigation
- Headings create document outline for screen readers
- Buttons and links are keyboard accessible by default
- Lists group related items
- Tables with proper headers are understandable

## Project-Specific Notes

**BotFacebook Semantic Structure:**
```
<header>        - Top nav, logo
  <nav>         - Main navigation
<main>          - Primary content
  <aside>       - Sidebar (if present)
  <section>     - Content sections
  <article>     - Standalone content (bot cards, messages)
<footer>        - Copyright, links
```

**Element Reference:**

| Content Type | Element |
|--------------|---------|
| Page header | `<header>` |
| Navigation | `<nav>` |
| Main content | `<main>` (one per page) |
| Sidebar | `<aside>` |
| Article/Card | `<article>` |
| Content section | `<section>` |
| Page footer | `<footer>` |
| List of items | `<ul>` / `<ol>` |
| Data | `<table>` |

## References

- [MDN Semantic HTML](https://developer.mozilla.org/en-US/docs/Glossary/Semantics)
- [WCAG 1.3.1 Info and Relationships](https://www.w3.org/WAI/WCAG21/Understanding/info-and-relationships.html)
