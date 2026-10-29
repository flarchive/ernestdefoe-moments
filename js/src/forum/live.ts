import app from 'flarum/forum/app';
import { flarumGlobal, has } from './util';

/**
 * Real-time nudges from flarum/realtime, when it is there.
 *
 * 🚨 A push carries an id, never content: a page told "something new" asks
 * the API what it may see. And "realtime installed" is not "socket
 * connected": whenever the socket is not actually up, feeds poll instead.
 */
type Listener = (event: 'new' | 'reply', data: { id: number; rootId?: number }) => void;

const listeners = new Set<Listener>();
let bound = false;

function bindOnce() {
  if (bound || !has('flarum-realtime')) return;
  bound = true;

  let rt: any = null;
  try {
    rt = flarumGlobal()?.reg?.get?.('flarum-realtime', 'forum/RealtimeState');
    rt = rt?.default ?? rt;
  } catch (e) {
    rt = null;
  }
  if (!rt) return;

  const bind = (channel: any) => {
    if (!channel?.bind) return;
    channel.bind('moments.new', (d: any) => listeners.forEach((fn) => fn('new', d)));
    channel.bind('moments.reply', (d: any) => listeners.forEach((fn) => fn('reply', d)));
  };

  rt.onPublicChannelReady?.(bind);
  rt.onUserChannelReady?.(bind);
}

export function listen(fn: Listener): () => void {
  bindOnce();
  listeners.add(fn);

  return () => listeners.delete(fn);
}

export function socketUp(): boolean {
  if (!bound) return false;
  const state = (app as any).websocket?.connection?.state;

  return state === 'connected';
}
