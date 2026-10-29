import app from 'flarum/admin/app';
import { registerEditorWidgets } from '../common/fofWidgets';

/*
 * Moments' admin is its settings and permissions; moments themselves are
 * written and moderated on the forum.
 */
app.initializers.add('ernestdefoe-moments', () => {
  const t = (key: string) => app.translator.trans(`ernestdefoe-moments.admin.${key}`);
  const s = (key: string) => `ernestdefoe-moments.${key}`;

  app.registry
    .for('ernestdefoe-moments')
    .registerSetting({ setting: s('show_nav'), type: 'boolean', label: t('settings.show_nav') })
    .registerSetting({ setting: s('char_limit'), type: 'number', min: 20, max: 5000, label: t('settings.char_limit') })
    .registerSetting({
      setting: s('default_privacy'),
      type: 'select',
      label: t('settings.default_privacy'),
      help: t('settings.default_privacy_help'),
      options: {
        public: t('settings.privacy_public'),
        followers: t('settings.privacy_followers'),
        private: t('settings.privacy_private'),
      },
      default: 'public',
    })
    .registerSetting({ setting: s('edit_window'), type: 'number', min: 0, label: t('settings.edit_window'), help: t('settings.edit_window_help') })
    .registerSetting({ setting: s('flood_seconds'), type: 'number', min: 0, label: t('settings.flood_seconds') })
    .registerSetting({ setting: s('max_media'), type: 'number', min: 1, max: 10, label: t('settings.max_media') })
    .registerSetting({ setting: s('image_max_kb'), type: 'number', min: 100, label: t('settings.image_max_kb') })
    .registerSetting({ setting: s('video_max_mb'), type: 'number', min: 1, label: t('settings.video_max_mb'), help: t('settings.media_help') })
    .registerSetting({ setting: s('voice_max_seconds'), type: 'number', min: 5, max: 900, label: t('settings.voice_max_seconds') })
    .registerSetting({ setting: s('giphy_key'), type: 'password', label: t('settings.giphy_key'), help: t('settings.giphy_key_help') } as any)
    .registerSetting({
      setting: s('giphy_rating'),
      type: 'select',
      label: t('settings.giphy_rating'),
      options: { g: 'G', pg: 'PG', 'pg-13': 'PG-13', r: 'R' },
      default: 'pg-13',
    })
    .registerPermission({ icon: 'fas fa-feather-pointed', label: t('permissions.view'), permission: 'moments.view', allowGuest: true }, 'view')
    .registerPermission({ icon: 'fas fa-pen', label: t('permissions.post'), permission: 'moments.post' }, 'start')
    .registerPermission({ icon: 'fas fa-reply', label: t('permissions.reply'), permission: 'moments.reply' }, 'reply')
    .registerPermission({ icon: 'fas fa-photo-film', label: t('permissions.upload'), permission: 'moments.uploadMedia' }, 'start')
    .registerPermission({ icon: 'fas fa-microphone', label: t('permissions.voice'), permission: 'moments.recordVoice' }, 'start')
    .registerPermission({ icon: 'fas fa-film', label: t('permissions.gifs'), permission: 'moments.useGifs' }, 'start')
    .registerPermission({ icon: 'fas fa-feather-pointed', label: t('permissions.moderate'), permission: 'moments.moderate' }, 'moderate');

  // FoF's layout editor lists only widgets the admin registers too.
  registerEditorWidgets(app, 'ernestdefoe-moments', [
    { key: 'momentsLatest', icon: 'fas fa-feather-pointed', title: () => t('widgets.latest') },
    { key: 'momentsTopics', icon: 'fas fa-hashtag', title: () => t('widgets.topics') },
    { key: 'momentsCompose', icon: 'fas fa-pen', title: () => t('widgets.compose') },
  ]);
});
