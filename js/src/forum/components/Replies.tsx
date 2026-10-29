import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import * as api from '../api';
import type { Config, MomentData } from '../api';
import { t } from '../util';
import MomentCard from './MomentCard';
import { loadComposer } from '../composerLoader';

/** Replies nest this deep; anything deeper is shown at this level, in order. */
export const MAX_DEPTH = 3;

interface Attrs {
  moment: MomentData;
  config: Config;
  focus?: number | null;
  onchange?: (replyCount: number) => void;
}

/**
 * A moment's replies: newest or most-reacted first, twenty threads at a
 * time, each thread nested to three levels.
 */
export default class Replies extends Component<Attrs> {
  replies: MomentData[] = [];
  sort: 'recent' | 'top' = 'recent';
  next: string | number | null = null;
  loading = true;
  replyingTo: number | null = null;
  Composer: any = null;
  asked = 0;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    this.load();
    if (this.attrs.moment.can.reply) loadComposer().then((C) => ((this.Composer = C), m.redraw()));
  }

  load(more = false) {
    this.loading = true;
    const ask = ++this.asked;
    api.getReplies(this.attrs.moment.id, this.sort, more ? this.next : null).then(
      (page) => {
        if (ask !== this.asked) return;
        this.replies = more ? [...this.replies, ...page.data] : page.data;
        this.next = page.meta.next;
        this.loading = false;
        m.redraw();
        if (this.attrs.focus && !more)
          requestAnimationFrame(() => document.getElementById(`moment-${this.attrs.focus}`)?.scrollIntoView({ block: 'center' }));
      },
      () => {
        this.loading = false;
        m.redraw();
      }
    );
  }

  children(parentId: number): MomentData[] {
    return this.replies.filter((r) => r.parentId === parentId);
  }

  /** Everything under a reply, oldest first, for the flattened deepest level. */
  descendants(parentId: number): MomentData[] {
    const out: MomentData[] = [];
    const walk = (id: number) =>
      this.children(id).forEach((child) => {
        out.push(child);
        walk(child.id);
      });
    walk(parentId);

    return out.sort((a, b) => a.id - b.id);
  }

  view(): Mithril.Children {
    const mo = this.attrs.moment;
    const heads = this.children(mo.id);

    return (
      <section className="MomentReplies" aria-label={t('replies.label')}>
        {mo.can.reply && this.Composer ? (
          <this.Composer config={this.attrs.config} parentId={mo.id} compact onposted={(r: MomentData) => this.added(r)} />
        ) : mo.isLocked ? (
          <p className="MomentReplies-locked">
            <i className="fas fa-lock" aria-hidden="true" /> {t('replies.locked')}
          </p>
        ) : null}

        {heads.length > 1 || this.sort === 'top' ? (
          <div className="MomentReplies-sort" role="group" aria-label={t('replies.sort')}>
            {(['recent', 'top'] as const).map((sort) => (
              <Button
                className={'Button Button--flat Button--small' + (this.sort === sort ? ' active' : '')}
                aria-pressed={this.sort === sort ? 'true' : 'false'}
                onclick={() => {
                  this.sort = sort;
                  this.load();
                }}
              >
                {t(`replies.${sort}`)}
              </Button>
            ))}
          </div>
        ) : null}

        <div className="MomentReplies-list">{heads.map((r) => this.node(r, 1))}</div>

        {this.loading ? <LoadingIndicator size="small" /> : null}
        {!this.loading && this.next ? (
          <Button className="Button Button--link MomentReplies-more" onclick={() => this.load(true)}>
            {t('replies.more')}
          </Button>
        ) : null}
      </section>
    );
  }

  node(reply: MomentData, depth: number): Mithril.Children {
    const nested = depth >= MAX_DEPTH ? this.descendants(reply.id) : this.children(reply.id);

    return (
      <div className={`MomentReply MomentReply--depth${Math.min(depth, MAX_DEPTH)}`} key={reply.id}>
        <MomentCard
          moment={reply}
          config={this.attrs.config}
          thread="none"
          focus={this.attrs.focus}
          onreply={(r: MomentData) => {
            this.replyingTo = this.replyingTo === r.id ? null : r.id;
          }}
          onchange={(r: MomentData) => this.replace(r)}
          ondelete={(id: number) => this.removed(id)}
        />
        {this.replyingTo === reply.id && this.Composer ? (
          <div className="MomentReply-composer">
            <this.Composer
              config={this.attrs.config}
              parentId={reply.id}
              compact
              autofocus
              placeholder={String(t('composer.reply_to', { name: api.user(reply.userId)?.displayName() || '' }))}
              oncancel={() => (this.replyingTo = null)}
              onposted={(r: MomentData) => this.added(r)}
            />
          </div>
        ) : null}
        {nested.length ? (
          <div className="MomentReply-children">
            {depth >= MAX_DEPTH ? nested.map((child) => this.flat(child)) : nested.map((child) => this.node(child, depth + 1))}
          </div>
        ) : null}
      </div>
    );
  }

  flat(reply: MomentData): Mithril.Children {
    return (
      <div className={`MomentReply MomentReply--depth${MAX_DEPTH} MomentReply--flat`} key={reply.id}>
        <MomentCard
          moment={reply}
          config={this.attrs.config}
          thread="none"
          focus={this.attrs.focus}
          onreply={(r: MomentData) => (this.replyingTo = this.replyingTo === r.id ? null : r.id)}
          onchange={(r: MomentData) => this.replace(r)}
          ondelete={(id: number) => this.removed(id)}
        />
        {this.replyingTo === reply.id && this.Composer ? (
          <div className="MomentReply-composer">
            <this.Composer
              config={this.attrs.config}
              parentId={reply.id}
              compact
              autofocus
              oncancel={() => (this.replyingTo = null)}
              onposted={(r: MomentData) => this.added(r)}
            />
          </div>
        ) : null}
      </div>
    );
  }

  added(reply: MomentData) {
    this.replyingTo = null;
    // A new thread goes on top (newest first); a reply inside one goes last (oldest first).
    this.replies = reply.parentId === this.attrs.moment.id ? [reply, ...this.replies] : [...this.replies, reply];
    this.attrs.onchange?.(this.attrs.moment.replyCount + 1);
  }

  replace(reply: MomentData) {
    this.replies = this.replies.map((r) => (r.id === reply.id ? reply : r));
  }

  removed(id: number) {
    const gone = new Set([id, ...this.descendants(id).map((r) => r.id)]);
    this.replies = this.replies.filter((r) => !gone.has(r.id));
    this.attrs.onchange?.(Math.max(0, this.attrs.moment.replyCount - gone.size));
    app.alerts.show({ type: 'success' }, t('card.deleted'));
  }
}
