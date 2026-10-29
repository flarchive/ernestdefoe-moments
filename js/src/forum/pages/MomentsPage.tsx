import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import LinkButton from 'flarum/common/components/LinkButton';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Placeholder from 'flarum/common/components/Placeholder';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import * as api from '../api';
import type { Config, MomentData } from '../api';
import { t, topicHref } from '../util';
import Feed, { FeedState } from '../components/Feed';
import { loadComposer } from '../composerLoader';

type Tab = 'everyone' | 'following' | 'bookmarks' | 'mine' | 'reported' | 'topic';

/** /moments — the timeline, with the composer on top and the feeds as tabs. */
export default class MomentsPage extends Page {
  config: Config | null = null;
  tab: Tab = 'everyone';
  topic = '';
  q = '';
  query = '';
  feed = new FeedState();
  Composer: any = null;
  topics: { name: string; key: string; count: number }[] = [];

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    this.topic = String(m.route.param('topic') || '').replace(/^#/, '');
    this.q = String(m.route.param('q') || '');
    this.query = this.q;
    const feed = String(m.route.param('feed') || '');
    this.tab = this.topic ? 'topic' : ((['following', 'bookmarks', 'mine', 'reported'].includes(feed) ? feed : 'everyone') as Tab);

    const title = this.topic ? '#' + this.topic : extractText(t('page.title'));
    app.setTitle(title);
    app.history.push('moments', title);

    api.loadConfig().then((config) => {
      this.config = config;
      if (config.canPost) loadComposer().then((C) => ((this.Composer = C), m.redraw()));
      m.redraw();
    });
    api.topics('popular', 8).then((topics) => ((this.topics = topics), m.redraw()));
  }

  filter(): Record<string, any> {
    const filter: Record<string, any> = { feed: this.tab };
    if (this.tab === 'topic') filter.topic = this.topic;
    if (this.q) filter.q = this.q;

    return filter;
  }

  href(params: Record<string, string>): string {
    const query = new URLSearchParams(Object.entries(params).filter(([, v]) => v) as any).toString();

    return app.route('moments.index') + (query ? '?' + query : '');
  }

  view(): Mithril.Children {
    return (
      <PageStructure className="MomentsPage" sidebar={() => <IndexSidebar />}>
        <div className="MomentsPage-head">
          <h2 className="MomentsPage-title">{this.tab === 'topic' ? '#' + this.topic : t('page.title')}</h2>
          <form
            className="MomentsPage-search"
            role="search"
            onsubmit={(e: SubmitEvent) => {
              e.preventDefault();
              m.route.set(
                this.href({ q: this.query.trim(), topic: this.topic, feed: this.tab === 'everyone' || this.tab === 'topic' ? '' : this.tab })
              );
            }}
          >
            <i className="fas fa-magnifying-glass" aria-hidden="true" />
            <input
              className="FormControl"
              type="search"
              placeholder={extractText(t('page.search'))}
              aria-label={extractText(t('page.search'))}
              value={this.query}
              oninput={(e: InputEvent) => (this.query = (e.target as HTMLInputElement).value)}
            />
          </form>
        </div>

        {this.config ? this.body(this.config) : <LoadingIndicator />}
      </PageStructure>
    );
  }

  body(config: Config): Mithril.Children {
    const tabs: [Tab, boolean][] = [
      ['everyone', true],
      ['following', config.following && !!app.session.user],
      ['bookmarks', !!app.session.user],
      ['mine', !!app.session.user],
      ['reported', config.canModerate],
    ];

    return (
      <>
        {config.canPost && this.tab !== 'reported' ? (
          this.Composer ? (
            <this.Composer config={config} onposted={(moment: MomentData) => this.feed.prepend(moment)} />
          ) : (
            <div className="MomentComposer MomentComposer--placeholder" />
          )
        ) : null}

        <nav className="MomentsPage-tabs" aria-label={extractText(t('page.feeds'))}>
          {tabs
            .filter(([, show]) => show)
            .map(([tab]) => (
              <LinkButton
                className={'Button Button--flat' + (this.tab === tab ? ' active' : '')}
                href={this.href({ feed: tab === 'everyone' ? '' : tab })}
                aria-current={this.tab === tab ? 'page' : undefined}
              >
                {t(`tabs.${tab}`)}
              </LinkButton>
            ))}
          {this.tab === 'topic' ? (
            <LinkButton
              className="Button Button--flat active MomentsPage-topicTab"
              icon="fas fa-xmark"
              href={this.href({})}
              aria-label={extractText(t('tabs.clear_topic'))}
            >
              {'#' + this.topic}
            </LinkButton>
          ) : null}
        </nav>

        {this.topics.length && this.tab !== 'topic' ? (
          <div className="MomentsPage-topics" aria-label={extractText(t('widgets.topics'))}>
            <span className="MomentsPage-topicsLabel">{t('page.trending')}</span>
            {this.topics.map((topic) => (
              <a
                className="MomentTopicChip"
                href={topicHref(topic.key)}
                onclick={(e: MouseEvent) => (e.preventDefault(), m.route.set(topicHref(topic.key)))}
              >
                #{topic.name}
              </a>
            ))}
          </div>
        ) : null}

        {this.q ? (
          <p className="MomentsPage-searching">
            {t('page.results_for', { q: this.q })}{' '}
            <a
              href={this.href({ topic: this.topic })}
              onclick={(e: MouseEvent) => (e.preventDefault(), m.route.set(this.href({ topic: this.topic })))}
            >
              {t('page.clear_search')}
            </a>
          </p>
        ) : null}

        <div className="MomentsPage-feed">
          <Feed
            key={JSON.stringify(this.filter())}
            filter={this.filter()}
            config={config}
            feedState={this.feed}
            live={!this.q && this.tab !== 'bookmarks' && this.tab !== 'reported'}
            empty={<Placeholder text={t(this.q ? 'feed.no_results' : `feed.empty_${this.tab}`)} />}
          />
        </div>
      </>
    );
  }
}
