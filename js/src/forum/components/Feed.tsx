import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Placeholder from 'flarum/common/components/Placeholder';
import type Mithril from 'mithril';
import * as api from '../api';
import type { Config, MomentData } from '../api';
import { listen, socketUp } from '../live';
import { t } from '../util';
import MomentCard from './MomentCard';

interface Attrs {
  filter: Record<string, any>;
  config: Config;
  empty?: Mithril.Children;
  /** Show "N new moments" for this feed. */
  live?: boolean;
  feedState?: FeedState;
}

/** A feed's moments, kept outside the component so the composer above it can add to it. */
export class FeedState {
  items: MomentData[] = [];
  next: number | null = null;
  loading = false;
  loaded = false;
  fresh = 0;

  prepend(moment: MomentData) {
    this.items = [moment, ...this.items.filter((x) => x.id !== moment.id)];
  }
}

const POLL = 30000;

/**
 * Moments newest first, loaded twenty at a time as you scroll (cursor
 * paging: nothing repeats or goes missing as new moments arrive).
 *
 * New moments are announced, not pushed into the page under your reading
 * position: "3 new moments — show". The count comes from realtime's nudge
 * when its socket is up, and otherwise from a cheap poll every 30 seconds
 * while the tab is visible (paused while it is hidden).
 */
export default class Feed extends Component<Attrs> {
  feed!: FeedState;
  observer: IntersectionObserver | null = null;
  timer = 0;
  unlisten: (() => void) | null = null;
  asked = 0;
  checking = false;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    this.feed = this.attrs.feedState || new FeedState();
    if (!this.feed.loaded) this.load();
  }

  oncreate(vnode: Mithril.VnodeDOM<Attrs, this>) {
    super.oncreate(vnode);

    const sentinel = this.element.querySelector('.MomentFeed-sentinel');
    if (sentinel && 'IntersectionObserver' in window) {
      this.observer = new IntersectionObserver((entries) => entries.some((e) => e.isIntersecting) && this.more(), { rootMargin: '600px' });
      this.observer.observe(sentinel);
    }

    if (this.attrs.live) {
      this.unlisten = listen((event) => event === 'new' && this.check());
      this.timer = window.setInterval(() => {
        if (document.visibilityState === 'visible' && !socketUp()) this.check();
      }, POLL);
      document.addEventListener('visibilitychange', this.onVisible);
    }
  }

  onremove(vnode: Mithril.VnodeDOM<Attrs, this>) {
    super.onremove(vnode);
    this.observer?.disconnect();
    clearInterval(this.timer);
    this.unlisten?.();
    document.removeEventListener('visibilitychange', this.onVisible);
  }

  onVisible = () => {
    if (document.visibilityState === 'visible' && !socketUp()) this.check();
  };

  load(before: number | null = null) {
    const s = this.feed;
    s.loading = true;
    const ask = ++this.asked;

    api.listMoments(this.attrs.filter, before).then(
      (page) => {
        if (ask !== this.asked) return;
        s.items = before ? [...s.items, ...page.data.filter((x) => !s.items.some((y) => y.id === x.id))] : page.data;
        s.next = page.meta.next as number | null;
        s.loading = false;
        s.loaded = true;
        if (!before) s.fresh = 0;
        m.redraw();
      },
      () => {
        s.loading = false;
        s.loaded = true;
        m.redraw();
      }
    );
  }

  more() {
    if (!this.feed.loading && this.feed.next) this.load(this.feed.next);
  }

  check() {
    const newest = this.feed.items.reduce((max, x) => Math.max(max, x.id), 0);
    if (this.checking || !this.feed.loaded) return;
    this.checking = true;
    api
      .newCount(this.attrs.filter, newest)
      .then((count) => {
        if (count !== this.feed.fresh) {
          this.feed.fresh = count;
          m.redraw();
        }
      })
      .finally(() => (this.checking = false));
  }

  view(): Mithril.Children {
    const s = this.feed;

    return (
      <div className="MomentFeed">
        {s.fresh > 0 ? (
          <Button
            className="Button Button--primary MomentFeed-fresh"
            icon="fas fa-arrow-up"
            onclick={() => (window.scrollTo({ top: 0, behavior: 'smooth' }), this.load())}
          >
            {t('feed.new', { count: s.fresh })}
          </Button>
        ) : null}

        <div className="MomentFeed-list" role="feed" aria-busy={s.loading ? 'true' : 'false'}>
          {s.items.map((moment) => (
            <MomentCard
              key={moment.id}
              moment={moment}
              config={this.attrs.config}
              thread="toggle"
              onchange={(changed: MomentData) => (s.items = s.items.map((x) => (x.id === changed.id ? changed : x)))}
              ondelete={(id: number) => {
                s.items = s.items.filter((x) => x.id !== id);
                app.alerts.show({ type: 'success' }, t('card.deleted'));
              }}
            />
          ))}
        </div>

        {s.loaded && !s.loading && !s.items.length ? this.attrs.empty || <Placeholder text={t('feed.empty')} /> : null}
        {s.loading ? <LoadingIndicator /> : null}
        {!s.loading && s.next ? (
          <Button className="Button MomentFeed-more" onclick={() => this.more()}>
            {t('feed.more')}
          </Button>
        ) : null}
        <div className="MomentFeed-sentinel" aria-hidden="true" />
      </div>
    );
  }
}
