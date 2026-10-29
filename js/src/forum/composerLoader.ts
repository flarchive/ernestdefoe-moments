let Composer: any = null;

/** The composer is its own chunk, shared by the feed, threads and the "post a moment" widget. */
export function loadComposer(): Promise<any> {
  return Composer ? Promise.resolve(Composer) : import('./components/Composer').then((mod) => (Composer = mod.default));
}

export function composer(): any {
  return Composer;
}
