import UserPage from 'flarum/forum/components/UserPage';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Placeholder from 'flarum/common/components/Placeholder';
import type User from 'flarum/common/models/User';
import type Mithril from 'mithril';
import * as api from '../api';
import type { Config } from '../api';
import { t } from '../util';
import Feed from '../components/Feed';

/** A member's Moments tab: their moments and reposts, each through the privacy rule. */
export default class UserMomentsPage extends UserPage {
  config: Config | null = null;

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);
    this.loadUser(m.route.param('username'));
    api.loadConfig().then((config) => {
      this.config = config;
      m.redraw();
    });
  }

  show(user: User) {
    super.show(user);
  }

  content(): Mithril.Children {
    if (!this.user || !this.config) return <LoadingIndicator />;

    return (
      <div className="UserMomentsPage MomentsPage">
        <Feed
          key={this.user.id()}
          filter={{ feed: 'user', user: this.user.id() }}
          config={this.config}
          empty={<Placeholder text={t('user.empty', { name: this.user.displayName() })} />}
        />
      </div>
    );
  }
}
