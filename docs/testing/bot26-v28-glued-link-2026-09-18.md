# Bot 26 — the glued link, and what fixing it uncovered (2026-09-18)

The last defect standing between the v28 artifact and a passing raw gate, recorded as `T09` in the
fixture manifest: the model closes a sentence with ครับ against a link.

> ติดต่อได้ที่ https://lin.ee/h5wYpIfครับ

Thai does not put spaces between words, so this is what the language looks like, and it landed on
whichever case happened to emit a link — T09, T18, T21 and T25 all produced it in measured runs.

## The reply was thrown away, not just the link

`CustomerReplyPolicy` extracted `https://lin.ee/h5wYpIfครับ`, compared it to the allowlist, found no
match and replaced **the entire reply** with `ขอเช็กข้อมูลล่าสุดให้ในแชทนี้ครับ`. The destination was the
shop's own Support link; only a Thai word was standing next to it.

A URL is ASCII by definition, so a non-ASCII character cannot be part of one and must end it. The
URL and handle scans now stop there. Everything the URL stops short of stays in the remaining text,
where the schemeless-domain rule still catches `https://lin.ee/h5wYpIfไป.evil.test`, and
`https://evil.test/helpครับ` is still rejected on its own merits. `@743ddeqyครับ` is now read as the
approved handle followed by a word, while `@743ddeqy_fake` and `@743ddeqy.th` stay rejected: those
are spelled entirely in characters a LINE ID may contain, so they are other destinations.

One trap worth recording. The exclusion has to be written `\P{ASCII}`, not a codepoint range such as
`\x{0080}-\x{10FFFF}`. Under the `/i` flag PCRE case-folds class ranges, and the non-ASCII planes
fold back onto ASCII letters — U+017F (ſ) onto `s`, U+212A (K) onto `k` — so the range silently cut
every URL short at its first `s`: `https://t.me/supermanth2022` matched as `https://t.me/`.

## The raw gate was checking contacts with its own copy of the rules

`Bot26PromptEvaluationTest::responseFailures()` read the allowlist from `CustomerReplyPolicy` but
re-implemented the extraction around it, with its own URL and handle regexes. So the gate kept
failing on the glued link after the policy was fixed. It now asks the policy for a verdict instead.
One rule, one implementation — the same lesson as the `@adsvance` allowlist gap, which existed for
exactly this reason.

## What that unified check then found

With the gate running the production policy, case T29 failed — and the reason was not the gate:

```
กฎหมายมีผล 1 พ.ย. 2026 ครับ     → corrected=true  ["contact_schemeless"]
ส่งของ 15 ม.ค. นี้ครับ           → corrected=true  ["contact_schemeless"]
```

The schemeless-domain rule matched Unicode letters, so **every Thai month abbreviation reads as a
domain**: `พ.ย` is letter-dot-letter exactly like `lin.ee`. Under `enforce`, any reply quoting a Thai
date — a delivery date, a warranty date, a Meta policy date — would have been replaced wholesale
with the fallback. This shop quotes dates constantly.

It was not an unknown. The repository had recorded the symptom as correct behaviour: the fixture
manifest listed `T29` under `guard_replacement_cases.contact_fallback`, and the test carried a
hardcoded `'T29' => CustomerReplyPolicy::FALLBACK` with the comment *"Historical date prose is
rejected by the existing contact policy"*. A false positive had been written down as a safety
outcome.

Domain labels are now ASCII, because domains are. Both records are removed, and T29's reply survives
with its meaning intact.

**The tradeoff, stated plainly:** a schemeless Thai-script IDN (`ตัวอย่าง.ไทย`, no scheme) is the one
destination this rule no longer sees. A Unicode lookalike carrying a scheme is still caught above by
exact host comparison, the contact allowlist is unchanged, and the alternative — blanking any reply
that mentions a Thai date — is a certain, frequent harm against a remote one.

## Verification

- Full backend suite: **2,381 tests, 14,032 assertions, 0 failures, 0 errors** (`memory_limit=2G`).
- Live raw runs after the change: **108 graded responses covering all 38 cases, 0 hard failures** —
  no glued link, and nothing else either. 27 of the 38 cases were graded three times; the rest fewer
  because OpenRouter returned `cURL error 28` (45s connect timeout) on a number of calls during this
  window. A timed-out call writes no provenance record and is an infrastructure outcome, not a
  graded one; it is counted as neither a pass nor a failure here. Earlier runs in the same session,
  before the fix, failed on `https://lin.ee/h5wYpIfครับ` for T09, T18, T21 and T25.
- The prompt was not touched, so the artifact stays at 24,916 characters, SHA-256
  `b88b8619faa911e2e76f92a08389c85dd695a02bc213bc272a93356bbdbd3473`.

The prompt's own rule (line 7: `ลิงก์ต้องเว้นวรรคจากข้อความหรืออยู่คนละบรรทัด`) is unchanged and still
worth enforcing for a separate reason: a link glued to a word may not render as a clickable link in
a real chat client. That is now a cosmetic issue rather than a destroyed reply.
