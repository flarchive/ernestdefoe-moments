import app from 'flarum/forum/app';

/** A forum-side Moments string. Every word on screen comes through here. */
export const t = (key: string, params: Record<string, unknown> = {}): any => app.translator.trans(`ernestdefoe-moments.forum.${key}`, params as any);

/**
 * `flarum` is a global the bundle never imports; reached through globalThis
 * so its absence is `undefined`, not a ReferenceError thrown out of an
 * initializer.
 */
export function flarumGlobal(): any {
  return typeof globalThis !== 'undefined' ? (globalThis as any).flarum : undefined;
}

/** Whether an extension with forum JS is enabled. Never a build-time import. */
export function has(id: string): boolean {
  const extensions = flarumGlobal()?.extensions;

  return !!extensions && id in extensions;
}

export const apiUrl = (path: string): string => app.forum.attribute('apiUrl') + path;

export const PRIVACY_ICONS: Record<string, string> = { public: 'fas fa-earth-americas', followers: 'fas fa-user-group', private: 'fas fa-lock' };

/** m:ss from milliseconds. */
export function duration(ms: number | null): string {
  if (!ms) return '';
  const s = Math.round(ms / 1000);

  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

/** A topic's feed. */
export function topicHref(key: string): string {
  return app.route('moments.index') + '?topic=' + encodeURIComponent(key.toLowerCase());
}
