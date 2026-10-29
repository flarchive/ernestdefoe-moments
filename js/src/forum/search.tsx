import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import GlobalSearch from 'flarum/forum/components/GlobalSearch';
import LinkButton from 'flarum/common/components/LinkButton';
import extractText from 'flarum/common/utils/extractText';
import { t } from './util';

interface Result {
  id: number;
  name: string;
  text: string;
}

/**
 * Moments in the forum's search dropdown. The feed endpoint does the search,
 * through the same privacy rule as the feed; only an excerpt is kept.
 */
class MomentsSearchSource {
  resource = 'moments';
  results = new Map<string, Result[]>();

  title() {
    return extractText(t('search.heading'));
  }

  isCached(query: string) {
    return this.results.has(query.toLowerCase());
  }

  async search(query: string, limit: number) {
    query = query.toLowerCase();
    const res: any = await app.request({
      method: 'GET',
      url: `${app.forum.attribute('apiUrl')}/moments`,
      params: { filter: { q: query }, page: { limit } },
    });
    const names: Record<string, string> = {};
    (res.included || []).forEach((u: any) => (names[u.id] = u.attributes.displayName));
    const parser = new DOMParser();

    this.results.set(
      query,
      (res.data || []).map((d: any) => ({
        id: d.id,
        name: names[d.userId] || '',
        text: (parser.parseFromString(d.html || '', 'text/html').body.textContent || '').trim().slice(0, 140),
      }))
    );
    m.redraw();
  }

  view(query: string) {
    return (this.results.get(query.toLowerCase()) || []).map((r) => (
      <li className="MomentsSearchResult" data-index={'moments' + r.id} data-id={r.id}>
        <a href={app.route('moments.show', { id: r.id })}>
          <strong>{r.name}</strong> <span>{r.text}</span>
        </a>
      </li>
    ));
  }

  customGrouping() {
    return false;
  }

  fullPage(query: string) {
    return (
      <li>
        <LinkButton icon="fas fa-search" href={app.route('moments.index', { q: query })}>
          {t('search.all', { query })}
        </LinkButton>
      </li>
    );
  }

  gotoItem(id: string) {
    return app.route('moments.show', { id });
  }
}

export default function registerSearch() {
  extend(GlobalSearch.prototype, 'sourceItems', function (items) {
    if (app.forum.attribute('canViewMoments')) items.add('moments', new MomentsSearchSource() as any, -10);
  });
}
