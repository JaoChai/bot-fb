import { describe, it, expect } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

function walk(dir: string, out: string[] = []): string[] {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) walk(p, out);
    else if (/\.(ts|tsx)$/.test(name)) out.push(p);
  }
  return out;
}

describe('Radix comes only from the radix-ui monolith', () => {
  it('no source file imports @radix-ui/*', () => {
    const src = join(__dirname, '..', '..');
    const offenders = walk(src).filter((f) => /from ['"]@radix-ui\//.test(readFileSync(f, 'utf8')));
    expect(offenders).toEqual([]);
  });

  it('package.json has no @radix-ui/* dependency', () => {
    const pkg = JSON.parse(readFileSync(join(__dirname, '..', '..', '..', 'package.json'), 'utf8'));
    const names = Object.keys({ ...pkg.dependencies, ...pkg.devDependencies });
    expect(names.filter((n) => n.startsWith('@radix-ui/'))).toEqual([]);
  });
});
