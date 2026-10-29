import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Avatar from 'flarum/common/components/Avatar';
import Button from 'flarum/common/components/Button';
import Dropdown from 'flarum/common/components/Dropdown';
import Link from 'flarum/common/components/Link';
import Tooltip from 'flarum/common/components/Tooltip';
import humanTime from 'flarum/common/helpers/humanTime';
import username from 'flarum/common/helpers/username';
import abbreviateNumber from 'flarum/common/utils/abbreviateNumber';
import type Mithril from 'mithril';
import * as api from '../api';
import type { Config, MomentData } from '../api';
import { reactionGlyph } from '../reactions';
import { PRIVACY_ICONS, t } from '../util';
import Body from './Body';
import Replies from './Replies';
import { composer, loadComposer } from '../composerLoader';
import MediaGrid from './MediaGrid';

export interface CardAttrs {
  moment: MomentData;
  config: Config;
  ondelete?: (id: number) => void;
  onchange?: (moment: MomentData) => void;
  /** Replies open under the card (feed) or are always shown (a moment's page). */
  thread?: 'toggle' | 'open' | 'none';
  focus?: number | null;
  compact?: boolean;
  onreply?: (moment: MomentData) => void;
}

/**
 * One moment or reply: who and when, what they said, their media and link
 * cards, and what you can do with it.
 */
export default class MomentCard extends Component<CardAttrs> {
  editing = false;
  picking = false;
  threadOpen = false;
  busy = false;

  oninit(vnode: Mithril.Vnode<CardAttrs, this>) {
    super.oninit(vnode);
    this.threadOpen = this.attrs.thread === 'open';
  }

  /** The attrs a nested card (a repost's source) shares, without Mithril's key. */
  inherited(): CardAttrs {
    const { key, ...rest } = this.attrs as CardAttrs & { key?: unknown };

    return rest;
  }

  get moment(): MomentData {
    return this.attrs.moment;
  }

  view(): Mithril.Children {
    const mo = this.moment;
    const source = mo.repostOf && !('unavailable' in mo.repostOf) ? (mo.repostOf as MomentData) : null;
    const pureRepost = !!mo.repostOf && !mo.html && !mo.media.length;
    const author = api.user(mo.userId);

    // A repost without a comment shows the moment it shares, under a "reposted" line.
    if (pureRepost) {
      return (
        <article className="MomentCard MomentCard--repost" id={`moment-${mo.id}`}>
          <div className="MomentCard-repostedBy">
            <i className="fas fa-retweet" aria-hidden="true" />{' '}
            {author ? <Link href={app.route.user(author)}>{t('card.reposted_by', { user: author })}</Link> : null}
            {this.menu()}
          </div>
          {source ? (
            <MomentCard {...this.inherited()} moment={source} ondelete={undefined} onchange={(s: MomentData) => this.changeSource(s)} />
          ) : (
            <div className="MomentCard-unavailable">{t('card.unavailable')}</div>
          )}
        </article>
      );
    }

    return (
      <article
        className={
          'MomentCard' +
          (mo.isHidden ? ' MomentCard--hidden' : '') +
          (this.attrs.focus === mo.id ? ' MomentCard--focus' : '') +
          (this.attrs.compact ? ' MomentCard--compact' : '')
        }
        id={`moment-${mo.id}`}
      >
        <div className="MomentCard-avatar">
          {author ? (
            <Link href={app.route.user(author)} aria-hidden="true" tabindex="-1">
              <Avatar user={author} />
            </Link>
          ) : (
            <Avatar user={null} />
          )}
        </div>
        <div className="MomentCard-main">
          {this.header(author)}
          {this.editing ? (
            this.editor()
          ) : (
            <>
              {mo.html ? <Body html={mo.html} hashtags={this.attrs.config.hashtags} /> : null}
              {mo.media.length ? <MediaGrid media={mo.media} /> : null}
              {mo.embeds.length ? this.embeds() : null}
              {mo.repostOf ? (
                <div className="MomentCard-quote">
                  {source ? (
                    <MomentCard
                      {...this.attrs}
                      moment={source}
                      compact
                      thread="none"
                      ondelete={undefined}
                      onchange={(s: MomentData) => this.changeSource(s)}
                    />
                  ) : (
                    <div className="MomentCard-unavailable">{t('card.unavailable')}</div>
                  )}
                </div>
              ) : null}
            </>
          )}
          {this.attrs.compact ? null : this.actions()}
          {this.threadOpen ? (
            <Replies moment={mo} config={this.attrs.config} focus={this.attrs.focus} onchange={(n: number) => this.patch({ replyCount: n })} />
          ) : null}
        </div>
      </article>
    );
  }

  header(author: any) {
    const mo = this.moment;

    return (
      <header className="MomentCard-header">
        {author ? (
          <Link className="MomentCard-author" href={app.route.user(author)}>
            {username(author)}
          </Link>
        ) : (
          <span className="MomentCard-author">{t('card.deleted_user')}</span>
        )}
        <Link className="MomentCard-time" href={app.route('moments.show', { id: mo.id })}>
          {humanTime(new Date(mo.createdAt))}
        </Link>
        {mo.privacy !== 'public' && mo.parentId === null ? (
          <Tooltip text={t(`privacy.${mo.privacy}`)}>
            <i className={'MomentCard-privacy ' + PRIVACY_ICONS[mo.privacy]} aria-label={t(`privacy.${mo.privacy}`)} />
          </Tooltip>
        ) : null}
        {mo.editedAt ? <span className="MomentCard-edited">{t('card.edited')}</span> : null}
        {mo.isLocked ? (
          <Tooltip text={t('card.locked')}>
            <i className="MomentCard-flag fas fa-lock" aria-label={t('card.locked')} />
          </Tooltip>
        ) : null}
        {mo.isHidden ? <span className="MomentCard-badge">{t('card.hidden')}</span> : null}
        {mo.reportCount ? <span className="MomentCard-badge MomentCard-badge--alert">{t('card.reports', { count: mo.reportCount })}</span> : null}
        {this.attrs.compact ? null : this.menu()}
      </header>
    );
  }

  embeds() {
    return (
      <div className="MomentEmbeds">
        {this.moment.embeds.map((e) => (
          <a className={`MomentEmbed MomentEmbed--${e.type}`} href={e.url}>
            {e.avatarUrl ? <img className="MomentEmbed-avatar" src={e.avatarUrl} alt="" loading="lazy" /> : null}
            <span className="MomentEmbed-text">
              <span className="MomentEmbed-kind">{t(`embed.${e.type}`)}</span>
              <span className="MomentEmbed-title">{e.title}</span>
              {e.excerpt ? <span className="MomentEmbed-excerpt">{e.excerpt}</span> : null}
              {e.author && e.type !== 'user' ? <span className="MomentEmbed-meta">{t('embed.by', { name: e.author })}</span> : null}
            </span>
          </a>
        ))}
      </div>
    );
  }

  actions() {
    const mo = this.moment;
    const cfg = this.attrs.config;
    const top = [...mo.reactions].sort((a, b) => b.count - a.count).slice(0, 3);

    return (
      <div className="MomentCard-actions">
        {this.attrs.thread !== 'none' || mo.can.reply ? (
          <Button
            className={'Button Button--link MomentAction' + (mo.replyCount ? '' : ' MomentAction--word')}
            icon="far fa-comment"
            aria-label={t('card.reply')}
            onclick={() => this.replyClicked()}
          >
            {mo.replyCount ? abbreviateNumber(mo.replyCount) : t('card.reply')}
          </Button>
        ) : null}

        <span className="MomentAction-react">
          <Button
            className={
              'Button Button--link MomentAction' + (mo.myReaction ? ' MomentAction--active' : '') + (top.length ? '' : ' MomentAction--word')
            }
            icon={top.length ? undefined : 'far fa-face-smile'}
            disabled={!mo.can.react}
            aria-label={t('card.react')}
            aria-expanded={this.picking ? 'true' : 'false'}
            onclick={() => (app.session.user ? (this.picking = !this.picking) : app.modal.show(() => import('flarum/forum/components/LogInModal')))}
          >
            {top.length ? (
              <span className="MomentReactions">
                {top.map((r) => reactionGlyph(r.key))}
                <span className="MomentReactions-count">{abbreviateNumber(mo.reactionCount)}</span>
              </span>
            ) : (
              t('card.react')
            )}
          </Button>
          {this.picking ? (
            <div className="MomentReactionPicker" role="menu">
              {cfg.reactions.map((key) => (
                <button
                  type="button"
                  role="menuitem"
                  className={'MomentReactionPicker-item' + (mo.myReaction === key ? ' is-active' : '')}
                  aria-label={key}
                  onclick={() => this.react(mo.myReaction === key ? null : key)}
                >
                  {reactionGlyph(key)}
                </button>
              ))}
            </div>
          ) : null}
        </span>

        {mo.parentId === null ? (
          mo.can.repost ? (
            <Dropdown
              className="MomentAction-repost"
              buttonClassName={
                'Button Button--link MomentAction' + (mo.reposted ? ' MomentAction--active' : '') + (mo.repostCount ? '' : ' MomentAction--word')
              }
              icon="fas fa-retweet"
              label={mo.repostCount ? abbreviateNumber(mo.repostCount) : t('card.repost')}
              accessibleToggleLabel={t('card.repost')}
            >
              <Button icon="fas fa-retweet" disabled={mo.reposted} onclick={() => this.repost()}>
                {mo.reposted ? t('card.reposted') : t('card.repost_now')}
              </Button>
              <Button icon="fas fa-quote-left" onclick={() => this.quote()}>
                {t('card.quote')}
              </Button>
            </Dropdown>
          ) : (
            <span className="Button Button--link MomentAction MomentAction--static" aria-label={t('card.repost')}>
              <i className="fas fa-retweet Button-icon" aria-hidden="true" />
              {mo.repostCount ? <span className="Button-label">{abbreviateNumber(mo.repostCount)}</span> : null}
            </span>
          )
        ) : null}

        {app.session.user ? (
          <Button
            className={'Button Button--link MomentAction' + (mo.bookmarked ? ' MomentAction--active' : '')}
            icon={mo.bookmarked ? 'fas fa-bookmark' : 'far fa-bookmark'}
            aria-label={mo.bookmarked ? t('card.unbookmark') : t('card.bookmark')}
            aria-pressed={mo.bookmarked ? 'true' : 'false'}
            onclick={() => this.bookmark()}
          />
        ) : null}

        <Button
          className="Button Button--link MomentAction"
          icon="fas fa-arrow-up-from-bracket"
          aria-label={t('card.share')}
          onclick={() => this.share()}
        />
      </div>
    );
  }

  menu() {
    const mo = this.moment;
    const items: Mithril.Children[] = [];

    if (mo.can.edit && !mo.repostOf)
      items.push(
        <Button icon="fas fa-pencil" onclick={() => this.edit()}>
          {t('card.edit')}
        </Button>
      );
    if (mo.can.lock)
      items.push(
        <Button icon={mo.isLocked ? 'fas fa-lock-open' : 'fas fa-lock'} onclick={() => this.update({ isLocked: !mo.isLocked })}>
          {mo.isLocked ? t('card.unlock') : t('card.lock')}
        </Button>
      );
    if (mo.can.hide)
      items.push(
        <Button icon={mo.isHidden ? 'fas fa-eye' : 'far fa-eye-slash'} onclick={() => this.update({ isHidden: !mo.isHidden })}>
          {mo.isHidden ? t('card.unhide') : t('card.hide')}
        </Button>
      );
    if (mo.reportCount)
      items.push(
        <Button icon="fas fa-check" onclick={() => this.dismiss()}>
          {t('card.dismiss')}
        </Button>
      );
    if (mo.can.report)
      items.push(
        <Button icon="fas fa-flag" onclick={() => this.report()}>
          {t('card.report')}
        </Button>
      );
    if (mo.can.delete)
      items.push(
        <Button icon="far fa-trash-can" onclick={() => this.remove()}>
          {t('card.delete')}
        </Button>
      );

    if (!items.length) return null;

    return (
      <Dropdown
        className="MomentCard-menu"
        buttonClassName="Button Button--icon Button--flat"
        icon="fas fa-ellipsis"
        menuClassName="Dropdown-menu--right"
        accessibleToggleLabel={t('card.more')}
      >
        {items}
      </Dropdown>
    );
  }

  editor() {
    const Composer = composer();
    if (!Composer) {
      loadComposer().then(() => m.redraw());
      return <div className="MomentCard-editing" />;
    }

    return (
      <Composer
        config={this.attrs.config}
        edit={this.moment}
        compact
        oncancel={() => {
          this.editing = false;
          m.redraw();
        }}
        onposted={(data: MomentData) => {
          this.editing = false;
          this.attrs.onchange?.(data);
        }}
      />
    );
  }

  // ── actions ──────────────────────────────────────────────────────────────

  patch(fields: Partial<MomentData>) {
    this.attrs.onchange?.({ ...this.moment, ...fields });
  }

  changeSource(source: MomentData) {
    this.attrs.onchange?.({ ...this.moment, repostOf: source });
  }

  replyClicked() {
    if (this.attrs.onreply) return this.attrs.onreply(this.moment);
    if (this.attrs.thread === 'toggle') {
      this.threadOpen = !this.threadOpen;
      return;
    }
    if (this.attrs.thread === 'none') m.route.set(app.route('moments.show', { id: this.moment.rootId || this.moment.id }));
  }

  react(reaction: string | null) {
    this.picking = false;
    api.react(this.moment.id, reaction).then(
      (r) => this.attrs.onchange?.({ ...r.data, repostOf: this.moment.repostOf }),
      () => m.redraw()
    );
  }

  bookmark() {
    const on = !this.moment.bookmarked;
    this.patch({ bookmarked: on });
    api.bookmark(this.moment.id, on).catch(() => this.patch({ bookmarked: !on }));
    if (on) app.alerts.show({ type: 'success' }, t('card.bookmarked'));
  }

  repost() {
    api
      .saveMoment({ repostOf: this.moment.id, privacy: this.attrs.config.defaultPrivacy === 'private' ? 'public' : this.attrs.config.defaultPrivacy })
      .then(() => {
        this.patch({ reposted: true, repostCount: this.moment.repostCount + 1 });
        app.alerts.show({ type: 'success' }, t('card.repost_done'));
      });
  }

  quote() {
    loadComposer().then((Composer) => {
      const host = document.createElement('div');
      host.className = 'MomentQuoteHost';
      this.element.querySelector('.MomentCard-main')?.appendChild(host);
      m.mount(host, {
        view: () => (
          <Composer
            config={this.attrs.config}
            repostOf={this.moment}
            compact
            oncancel={() => {
              m.mount(host, null);
              host.remove();
            }}
            onposted={() => {
              m.mount(host, null);
              host.remove();
              this.patch({ reposted: true, repostCount: this.moment.repostCount + 1 });
              app.alerts.show({ type: 'success' }, t('card.repost_done'));
            }}
          />
        ),
      });
    });
  }

  share() {
    const url = this.moment.url;
    if ((navigator as any).share && window.matchMedia('(pointer: coarse)').matches) {
      (navigator as any).share({ url }).catch(() => {});
      return;
    }
    navigator.clipboard?.writeText(url).then(
      () => app.alerts.show({ type: 'success' }, t('card.link_copied')),
      () => window.prompt('', url)
    );
  }

  edit() {
    this.editing = true;
    loadComposer().then(() => m.redraw());
  }

  update(body: Record<string, any>) {
    api.saveMoment(body, this.moment.id).then((r) => this.attrs.onchange?.({ ...r.data, repostOf: r.data.repostOf ?? this.moment.repostOf }));
  }

  remove() {
    if (!confirm(String(t(this.moment.parentId ? 'card.delete_reply_confirm' : 'card.delete_confirm')))) return;
    api.deleteMoment(this.moment.id).then(() => this.attrs.ondelete?.(this.moment.id));
  }

  report() {
    app.modal.show(() => import('./ReportModal'), { moment: this.moment });
  }

  dismiss() {
    api.dismissReports(this.moment.id).then(() => this.patch({ reportCount: 0 }));
  }
}
