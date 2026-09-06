import { describe, it, expect } from 'vitest';
import { readdirSync, readFileSync, statSync, existsSync } from 'node:fs';
import { join } from 'node:path';

function walk(dir: string, out: string[] = []): string[] {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) walk(p, out);
    else if (/\.(ts|tsx)$/.test(name)) out.push(p);
  }
  return out;
}

describe('legacy conversation hooks are gone', () => {
  const src = join(__dirname, '..', '..');

  it('no file imports the legacy useConversations shim or the conversations folder', () => {
    const self = __filename;
    const offenders = walk(src).filter(
      (f) => f !== self && /@\/hooks\/(useConversations|conversations)\b/.test(readFileSync(f, 'utf8'))
    );
    expect(offenders).toEqual([]);
  });

  it('the legacy modules no longer exist', () => {
    expect(existsSync(join(src, 'hooks', 'useConversations.ts'))).toBe(false);
    expect(existsSync(join(src, 'hooks', 'conversations'))).toBe(false);
  });
});
