import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type Mithril from 'mithril';
import { fof, hasFofWidgets, placed } from '../common/fofWidgets';
import { has, t } from './util';

/**
 * Moments' widgets — latest moments, popular topics and "post a moment" —
 * offered to both widget hosts: Bespoke and fof/forum-widgets-core.
 *
 * 🚨 Neither host is a build-time dependency: each is found at runtime
 * through its own registry or queue, so this bundle is the same with both,
 * either or neither installed. What a widget shows is its own lazy chunk,
 * fetched only by a page that actually places it. FoF widgets are OPT-IN.
 */

type Kind = 'latest' | 'topics' | 'compose';

let body: any = null;

class MomentsWidget extends Component<{ kind: Kind; limit?: number }> {
  oninit(vnode: Mithril.Vnode<{ kind: Kind; limit?: number }, this>) {
    super.oninit(vnode);
    if (!body) {
      import('./components/WidgetBody').then((mod) => {
        body = mod.default;
        m.redraw();
      });
    }
  }

  view(): Mithril.Children {
    if (!app.forum.attribute('canViewMoments')) return null;
    const limit = Math.max(1, Math.min(20, Number(this.attrs.limit) || 5));

    return body ? m(body, { kind: this.attrs.kind, limit }) : <div className="MomentsWidget" />;
  }
}

const WIDGETS: { key: string; kind: Kind; icon: string }[] = [
  { key: 'momentsLatest', kind: 'latest', icon: 'fas fa-feather-pointed' },
  { key: 'momentsTopics', kind: 'topics', icon: 'fas fa-hashtag' },
  { key: 'momentsCompose', kind: 'compose', icon: 'fas fa-pen' },
];

export default function registerWidgets(): void {
  if (hasFofWidgets() && (app as any).widgets) {
    const Widgets = fof('common/extend/Widgets');
    const WidgetBase = fof('common/components/Widget');

    // A version whose internals moved: decline quietly rather than throw.
    if (Widgets && WidgetBase) {
      const add = new Widgets();

      WIDGETS.forEach((w, position) => {
        class FofMomentsWidget extends WidgetBase {
          className() {
            return 'MomentsFofWidget';
          }
          icon() {
            return w.icon;
          }
          title() {
            return t(`widgets.${w.kind}`);
          }
          content() {
            return <MomentsWidget kind={w.kind} />;
          }
        }

        add.add({
          key: w.key,
          component: FofMomentsWidget,
          // 🚨 Opt-in: only where the admin placed it, and only for viewers who may see moments.
          isDisabled: () => !placed(app, 'ernestdefoe-moments', w.key) || !app.forum.attribute('canViewMoments'),
          isUnique: true,
          placement: 'end',
          position,
        });
      });

      add.extend(app, 'ernestdefoe-moments');
    }
  }

  if (has('ernestdefoe-bespoke')) {
    const g = globalThis as any;
    g.BespokeWidgetQueue = g.BespokeWidgetQueue || [];

    WIDGETS.forEach((w) =>
      // 🚨 Labels are translation KEYS: Bespoke resolves them itself.
      g.BespokeWidgetQueue.push({
        type: `moments-${w.kind}`,
        label: `ernestdefoe-moments.forum.widgets.${w.kind}`,
        icon: w.icon,
        zones: ['sidebar', 'above-list', 'below-list', 'footer'],
        schema: w.kind === 'compose' ? [] : [{ key: 'limit', type: 'number', label: 'ernestdefoe-moments.forum.widgets.limit', default: 5 }],
        component: {
          view(vnode: any) {
            const s = vnode.attrs?.settings || {};
            return (
              <div className="MomentsBespokeWidget">
                <h4 className="MomentsWidget-heading">{t(`widgets.${w.kind}`)}</h4>
                <MomentsWidget kind={w.kind} limit={s.limit} />
              </div>
            );
          },
        },
      })
    );
  }
}
