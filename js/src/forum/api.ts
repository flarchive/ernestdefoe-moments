import app from 'flarum/forum/app';
import { apiUrl } from './util';

export interface MediaItem {
  id: number;
  kind: 'image' | 'video' | 'audio' | 'gif';
  url: string;
  mime: string | null;
  width: number | null;
  height: number | null;
  duration: number | null;
}

export interface Embed {
  type: 'discussion' | 'post' | 'user' | 'moment';
  url: string;
  title: string | null;
  excerpt?: string | null;
  author?: string | null;
  avatarUrl?: string | null;
  replies?: number;
}

export interface MomentData {
  id: number;
  userId: number;
  rootId: number | null;
  parentId: number | null;
  threadId: number | null;
  depth: number;
  html: string;
  privacy: 'public' | 'followers' | 'private';
  createdAt: string;
  editedAt: string | null;
  isHidden: boolean;
  isLocked: boolean;
  media: MediaItem[];
  replyCount: number;
  repostCount: number;
  reactionCount: number;
  reactions: { key: string; count: number }[];
  myReaction: string | null;
  bookmarked: boolean;
  reposted: boolean;
  reportCount: number | null;
  embeds: Embed[];
  url: string;
  can: Record<'edit' | 'delete' | 'hide' | 'lock' | 'reply' | 'report' | 'repost' | 'react', boolean>;
  repostOf?: MomentData | { id: number; unavailable: true };
}

export interface Config {
  canPost: boolean;
  canReply: boolean;
  canUploadMedia: boolean;
  canRecordVoice: boolean;
  canUseGifs: boolean;
  canModerate: boolean;
  charLimit: number;
  maxMedia: number;
  imageMaxBytes: number;
  videoMaxBytes: number;
  voiceMaxSeconds: number;
  defaultPrivacy: 'public' | 'followers' | 'private';
  following: boolean;
  fofUpload: boolean;
  hashtags: boolean;
  reactions: string[];
  fofReactions: boolean;
  realtime: boolean;
}

export function request<T = any>(method: string, path: string, options: { params?: any; body?: any; raw?: boolean } = {}): Promise<T> {
  return app.request<T>({
    method,
    url: apiUrl(path),
    params: options.params,
    body: options.body,
    ...(options.raw ? { serialize: (raw: any) => raw } : {}),
  } as any);
}

/** The authors that came with a response go into the forum's store, so avatars, names and user cards work. */
export function absorb<T extends { included?: any[] }>(response: T): T {
  if (response?.included?.length) app.store.pushPayload({ data: response.included } as any);

  return response;
}

export function user(id: number): any {
  return app.store.getById('users', String(id));
}

let config: Promise<Config> | null = null;

/** What the composer and feed need, once per page load. */
export function loadConfig(): Promise<Config> {
  return (config ??= request<{ data: Config }>('GET', '/moments-config').then((r) => r.data));
}

export interface Page {
  data: MomentData[];
  included: any[];
  meta: { next: number | string | null; newest?: number | null; sort?: string; focus?: number };
}

export function listMoments(filter: Record<string, any>, before?: number | null, limit = 20): Promise<Page> {
  const params: any = { filter, page: { limit } };
  if (before) params.page.before = before;

  return request<Page>('GET', '/moments', { params }).then(absorb);
}

export function getMoment(id: number | string): Promise<{ data: MomentData; included: any[]; meta: { focus?: number } }> {
  return request('GET', `/moments/${id}`).then(absorb);
}

export function getReplies(id: number, sort: 'recent' | 'top', after?: string | number | null): Promise<Page> {
  const params: any = { sort };
  if (after) params.page = { after };

  return request<Page>('GET', `/moments/${id}/replies`, { params }).then(absorb);
}

export function saveMoment(body: Record<string, any>, id?: number): Promise<{ data: MomentData; included: any[] }> {
  return request(id ? 'PATCH' : 'POST', id ? `/moments/${id}` : '/moments', { body }).then(absorb);
}

export function deleteMoment(id: number): Promise<void> {
  return request('DELETE', `/moments/${id}`);
}

export function react(id: number, reaction: string | null): Promise<{ data: MomentData; included: any[] }> {
  return request(reaction ? 'POST' : 'DELETE', `/moments/${id}/reaction`, reaction ? { body: { reaction } } : {}).then(absorb);
}

export function bookmark(id: number, on: boolean): Promise<{ bookmarked: boolean }> {
  return request(on ? 'POST' : 'DELETE', `/moments/${id}/bookmark`);
}

export function report(id: number, reason: string, detail: string): Promise<void> {
  return request('POST', `/moments/${id}/reports`, { body: { reason, detail } });
}

export function dismissReports(id: number): Promise<void> {
  return request('DELETE', `/moments/${id}/reports`);
}

export function newCount(filter: Record<string, any>, after: number): Promise<number> {
  return request<{ count: number }>('GET', '/moments-count', { params: { filter, after } }).then((r) => r.count);
}

export function uploadFile(file: Blob, purpose: 'media' | 'voice', duration?: number, name = 'upload'): Promise<MediaItem> {
  const body = new FormData();
  body.append('file', file, name);
  body.append('purpose', purpose);
  if (duration) body.append('duration', String(Math.round(duration)));

  return request<{ data: MediaItem }>('POST', '/moments-media', { body, raw: true }).then((r) => r.data);
}

/** With fof/upload: the file goes through it, then is attached by id. */
export function uploadThroughFof(file: File): Promise<MediaItem> {
  const body = new FormData();
  body.append('files[]', file, file.name);

  return app.request<any>({ method: 'POST', url: apiUrl('/fof/upload'), body, serialize: (raw: any) => raw } as any).then((r: any) => {
    const id = Array.isArray(r?.data) ? r.data[0]?.id : r?.data?.id;
    return request<{ data: MediaItem }>('POST', '/moments-media', { body: { fofFileId: Number(id) } }).then((res) => res.data);
  });
}

export function attachGif(gif: { url: string; width: number; height: number }): Promise<MediaItem> {
  return request<{ data: MediaItem }>('POST', '/moments-media', { body: { gif } }).then((r) => r.data);
}

export function searchGifs(q: string, offset = 0): Promise<any[]> {
  return request<{ data: any[] }>('GET', '/moments-gifs', { params: { q, offset } }).then((r) => r.data);
}

export function topics(sort: 'popular' | 'active', limit: number): Promise<{ name: string; key: string; count: number }[]> {
  return request<{ data: any[] }>('GET', '/moments-topics', { params: { sort, limit } }).then((r) => r.data);
}
