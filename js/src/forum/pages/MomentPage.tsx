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
import { t } from '../util';
import MomentCard from '../components/MomentCard';

/** /moments/:id — one moment and its whole thread; a reply's link opens the thread at the reply. */
export default class MomentPage extends Page {
  moment: MomentData | null = null;
  config: Config | null = null;
  focus: number | null = null;
  missing = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    app.history.push('moment', extractText(t('page.title')));

    Promise.all([api.loadConfig(), api.getMoment(m.route.param('id'))]).then(
      ([config, response]) => {
        this.config = config;
        this.moment = response.data;
        this.focus = response.meta?.focus ?? null;
        const author = api.user(this.moment.userId);
        app.setTitle(extractText(t('page.moment_title', { name: author?.displayName() || '' })));
        m.redraw();
      },
      () => {
        this.missing = true;
        m.redraw();
      }
    );
  }

  view(): Mithril.Children {
    return (
      <PageStructure className="MomentsPage MomentPage" sidebar={() => <IndexSidebar />}>
        <div className="MomentPage-back">
          <LinkButton className="Button Button--flat" icon="fas fa-arrow-left" href={app.route('moments.index')}>
            {t('page.back')}
          </LinkButton>
        </div>
        {this.missing ? (
          <Placeholder text={t('page.not_found')} />
        ) : this.moment && this.config ? (
          <MomentCard
            moment={this.moment}
            config={this.config}
            thread="open"
            focus={this.focus}
            onchange={(moment: MomentData) => (this.moment = moment)}
            ondelete={() => m.route.set(app.route('moments.index'))}
          />
        ) : (
          <LoadingIndicator />
        )}
      </PageStructure>
    );
  }
}
