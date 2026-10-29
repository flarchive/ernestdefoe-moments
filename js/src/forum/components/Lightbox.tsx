import Component from 'flarum/common/Component';
import type Mithril from 'mithril';
import type { MediaItem } from '../api';
import { t } from '../util';

/**
 * Full-screen viewer for a moment's photos and video: arrows, swipe, Esc,
 * and focus kept inside while it is open. Its own lazy chunk.
 */
class LightboxView extends Component<{ items: MediaItem[]; index: number; onclose: () => void }> {
  index = 0;
  startX: number | null = null;
  keys = (e: KeyboardEvent) => {
    if (e.key === 'Escape') this.attrs.onclose();
    if (e.key === 'ArrowRight') this.go(1);
    if (e.key === 'ArrowLeft') this.go(-1);
  };

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);
    this.index = this.attrs.index;
  }

  oncreate(vnode: Mithril.VnodeDOM<any, this>) {
    super.oncreate(vnode);
    document.addEventListener('keydown', this.keys);
    (this.element.querySelector('.MomentLightbox-close') as HTMLElement)?.focus();
  }

  onremove(vnode: Mithril.VnodeDOM<any, this>) {
    super.onremove(vnode);
    document.removeEventListener('keydown', this.keys);
  }

  go(step: number) {
    const n = this.attrs.items.length;
    this.index = (this.index + step + n) % n;
    m.redraw();
  }

  view(): Mithril.Children {
    const item = this.attrs.items[this.index];
    const many = this.attrs.items.length > 1;

    return (
      <div
        className="MomentLightbox"
        role="dialog"
        aria-modal="true"
        aria-label={t('media.viewer')}
        onclick={(e: MouseEvent) => e.target === e.currentTarget && this.attrs.onclose()}
        ontouchstart={(e: TouchEvent) => (this.startX = e.touches[0].clientX)}
        ontouchend={(e: TouchEvent) => {
          if (this.startX === null) return;
          const dx = e.changedTouches[0].clientX - this.startX;
          this.startX = null;
          if (Math.abs(dx) > 50 && many) this.go(dx < 0 ? 1 : -1);
        }}
      >
        <button type="button" className="MomentLightbox-close Button Button--icon" aria-label={t('media.close')} onclick={() => this.attrs.onclose()}>
          <i className="fas fa-xmark" aria-hidden="true" />
        </button>
        {many ? (
          <button
            type="button"
            className="MomentLightbox-nav MomentLightbox-nav--prev Button Button--icon"
            aria-label={t('media.previous')}
            onclick={() => this.go(-1)}
          >
            <i className="fas fa-chevron-left" aria-hidden="true" />
          </button>
        ) : null}
        <div className="MomentLightbox-stage">
          {item.kind === 'video' ? (
            <video key={item.id} className="MomentLightbox-media" src={item.url} controls autoplay playsinline />
          ) : (
            <img key={item.id} className="MomentLightbox-media" src={item.url} alt="" />
          )}
        </div>
        {many ? (
          <button
            type="button"
            className="MomentLightbox-nav MomentLightbox-nav--next Button Button--icon"
            aria-label={t('media.next')}
            onclick={() => this.go(1)}
          >
            <i className="fas fa-chevron-right" aria-hidden="true" />
          </button>
        ) : null}
        {many ? <div className="MomentLightbox-count">{t('media.count', { index: this.index + 1, total: this.attrs.items.length })}</div> : null}
      </div>
    );
  }
}

export default class Lightbox {
  static open(items: MediaItem[], index: number) {
    const host = document.createElement('div');
    document.body.appendChild(host);
    document.body.classList.add('MomentLightbox-open');
    const opener = document.activeElement as HTMLElement | null;

    const close = () => {
      m.mount(host, null);
      host.remove();
      document.body.classList.remove('MomentLightbox-open');
      opener?.focus?.();
    };

    m.mount(host, { view: () => <LightboxView items={items} index={index} onclose={close} /> });
  }
}
