import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Avatar from 'flarum/common/components/Avatar';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';
import username from 'flarum/common/helpers/username';
import type Mithril from 'mithril';
import * as api from '../api';
import type { Config, MomentData } from '../api';
import { t, topicHref } from '../util';
import { loadComposer } from '../composerLoader';

interface Attrs {
  kind: 'latest' | 'topics' | 'compose';
  limit: number;
}

/** What a placed widget shows. Its own lazy chunk, fetched only where a widget is placed. */
export default class WidgetBody extends Component<Attrs> {
  moments: MomentData[] | null = null;
  topics: { name: string; key: string; count: number }[] | null = null;
  config: Config | null = null;
  Composer: any = null;
  posted = false;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);

    if (this.attrs.kind === 'latest') {
      api.listMoments({ feed: 'everyone' }, null, this.attrs.limit).then((page) => ((this.moments = page.data), m.redraw()));
    } else if (this.attrs.kind === 'topics') {
      api.topics('popular', this.attrs.limit).then((topics) => ((this.topics = topics), m.redraw()));
    } else {
      api.loadConfig().then((config) => {
        this.config = config;
        if (config.canPost) loadComposer().then((C) => ((this.Composer = C), m.redraw()));
        m.redraw();
      });
    }
  }

  view(): Mithril.Children {
    if (this.attrs.kind === 'compose') return this.compose();
    if (this.attrs.kind === 'topics') return this.topicList();

    if (!this.moments) return <LoadingIndicator size="small" />;
    if (!this.moments.length) return <p className="MomentsWidget-empty">{t('feed.empty')}</p>;

    return (
      <ul className="MomentsWidget MomentsWidget--latest">
        {this.moments.map((mo) => {
          const user = api.user(mo.userId);
          const text = (mo.html ? new DOMParser().parseFromString(mo.html, 'text/html').body.textContent || '' : '').trim();

          return (
            <li className="MomentsWidget-item">
              <Avatar user={user} />
              <div className="MomentsWidget-text">
                <Link href={app.route('moments.show', { id: mo.id })} className="MomentsWidget-link">
                  <strong>{user ? username(user) : ''}</strong> <span className="MomentsWidget-time">{humanTime(new Date(mo.createdAt))}</span>
                </Link>
                <span className="MomentsWidget-excerpt">
                  {text ? text.slice(0, 120) : mo.media.length ? t('widgets.media') : mo.repostOf ? t('widgets.repost') : ''}
                </span>
              </div>
            </li>
          );
        })}
        <li className="MomentsWidget-all">
          <Link href={app.route('moments.index')}>{t('widgets.all')}</Link>
        </li>
      </ul>
    );
  }

  topicList(): Mithril.Children {
    if (!this.topics) return <LoadingIndicator size="small" />;
    if (!this.topics.length) return <p className="MomentsWidget-empty">{t('widgets.no_topics')}</p>;

    return (
      <ul className="MomentsWidget MomentsWidget--topics">
        {this.topics.map((topic) => (
          <li>
            <Link href={topicHref(topic.key)} className="MomentsWidget-topic">
              <span>#{topic.name}</span>
              <span className="MomentsWidget-count">{t('widgets.topic_count', { count: topic.count })}</span>
            </Link>
          </li>
        ))}
      </ul>
    );
  }

  compose(): Mithril.Children {
    if (!this.config) return <LoadingIndicator size="small" />;
    if (!this.config.canPost) {
      return (
        <p className="MomentsWidget-empty">
          <Link href={app.route('moments.index')}>{t('widgets.all')}</Link>
        </p>
      );
    }
    if (this.posted) {
      return (
        <p className="MomentsWidget-done">
          {t('widgets.posted')} <Link href={app.route('moments.index')}>{t('widgets.all')}</Link>
        </p>
      );
    }

    return this.Composer ? <this.Composer config={this.config} compact onposted={() => (this.posted = true)} /> : <LoadingIndicator size="small" />;
  }
}
