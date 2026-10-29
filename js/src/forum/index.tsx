import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import UserPage from 'flarum/forum/components/UserPage';
import LinkButton from 'flarum/common/components/LinkButton';
import UserPageResolver from 'flarum/forum/resolvers/UserPageResolver';
import Moment from './models/Moment';
import { notificationIcons, notificationTypes } from './notifications';
import registerWidgets from './widgets';
import registerSearch from './search';
import { t } from './util';

/*
 * Everything here runs on every page for every visitor, so it is only the
 * wiring: routes, the nav link, the profile tab, the notification types and
 * the widget registrations. The feed, the composer, the lightbox, the voice
 * recorder and the GIF picker are their own chunks.
 */
app.initializers.add('ernestdefoe-moments', () => {
  app.routes['moments.index'] = { path: '/moments', component: () => import('./ui').then((mod) => ({ default: mod.MomentsPage })) };
  app.routes['moments.show'] = { path: '/moments/:id', component: () => import('./ui').then((mod) => ({ default: mod.MomentPage })) };
  app.routes['user.moments'] = {
    path: '/u/:username/moments',
    component: () => import('./ui').then((mod) => ({ default: mod.UserMomentsPage })),
    resolverClass: UserPageResolver,
  } as any;

  app.store.models.moments = Moment;
  Object.assign(app.notificationComponents, notificationTypes);

  extend(IndexSidebar.prototype, 'navItems', function (items) {
    if (!app.forum.attribute('canViewMoments') || app.forum.attribute('momentsShowNav') === false) return;

    items.add(
      'moments',
      <LinkButton href={app.route('moments.index')} icon="fas fa-feather-pointed">
        {t('nav')}
      </LinkButton>,
      85
    );
  });

  extend(UserPage.prototype, 'navItems', function (this: any, items) {
    if (!app.forum.attribute('canViewMoments') || !this.user) return;

    items.add(
      'moments',
      <LinkButton href={app.route('user.moments', { username: this.user.slug() })} name="moments" icon="fas fa-feather-pointed">
        {t('user.tab')}
      </LinkButton>,
      95
    );
  });

  // The notification settings grid lives in a lazy chunk: extended by name.
  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: any) {
    if (!app.forum.attribute('canViewMoments')) return;

    Object.keys(notificationIcons).forEach((type) => items.add(type, { name: type, icon: notificationIcons[type], label: t(`settings.${type}`) }));
  });

  registerWidgets();
  registerSearch();
});
