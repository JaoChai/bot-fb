import { describe, expect, it } from 'vitest';
import { router } from './router';

/**
 * Guards against links pointing at routes that don't exist — the failure mode
 * that shipped /connections (AlertStrip) and /forgot-password (LoginPage), both
 * of which dropped the user on an error screen.
 */

type RouteLike = { path?: string; index?: boolean; children?: RouteLike[] };

function collectPaths(routes: RouteLike[], parent = ''): string[] {
  return routes.flatMap((route) => {
    const joined = route.path?.startsWith('/')
      ? route.path
      : route.path
        ? `${parent}/${route.path}`.replace('//', '/')
        : parent;
    const self = route.path ? [joined] : [];
    return [...self, ...collectPaths(route.children ?? [], joined)];
  });
}

const routePaths = collectPaths(router.routes as RouteLike[]);

/** `/bots/:botId/settings` → matches `/bots/26/settings` */
function isKnownRoute(link: string): boolean {
  return routePaths.some((path) => {
    if (path === '*' || path.endsWith('/*')) return false; // catch-all must not vouch for anything
    const pattern = new RegExp(`^${path.replace(/:[^/]+/g, '[^/]+')}$`);
    return pattern.test(link);
  });
}

// Raw source of every component, so we can read the literal `to="/..."` values
const sources = import.meta.glob('./**/*.tsx', { query: '?raw', import: 'default', eager: true });

function staticLinks(): { file: string; link: string }[] {
  return Object.entries(sources).flatMap(([file, code]) =>
    [...(code as string).matchAll(/\bto="(\/[^"]*)"/g)].map((m) => ({ file, link: m[1] })),
  );
}

describe('ลิงก์ทุกจุดในเว็บต้องชี้ไป route ที่มีอยู่จริง', () => {
  it('เจอลิงก์แบบเขียนตรงๆ อย่างน้อย 10 จุด (กันเทสต์ผ่านเพราะหาไม่เจอ)', () => {
    expect(staticLinks().length).toBeGreaterThanOrEqual(10);
  });

  it('ไม่มีลิงก์ไหนชี้ไป route ที่ไม่มีใน router', () => {
    const broken = staticLinks().filter(({ link }) => !isKnownRoute(link));
    expect(broken).toEqual([]);
  });
});
