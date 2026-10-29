import Component from 'flarum/common/Component';
import type Mithril from 'mithril';
import type { MediaItem } from '../api';
import { duration, t } from '../util';

let Lightbox: any = null;

function openLightbox(items: MediaItem[], index: number) {
  const show = () => Lightbox.open(items, index);
  if (Lightbox) return show();
  import('./Lightbox').then((mod) => {
    Lightbox = mod.default;
    show();
  });
}

/**
 * Photos, video and GIFs laid out by count — one full width, two side by
 * side, three as one tall and two stacked, four as a square grid — and five
 * or more as a swipeable carousel. Everything is lazy: images load as they
 * near the screen, video fetches nothing until played. A voice note is its
 * own player.
 */
export default class MediaGrid extends Component<{ media: MediaItem[] }> {
  view(): Mithril.Children {
    const visual = this.attrs.media.filter((x) => x.kind !== 'audio');
    const audio = this.attrs.media.filter((x) => x.kind === 'audio');

    return (
      <div className="MomentMedia">
        {visual.length ? this.grid(visual) : null}
        {audio.map((a) => (
          <div className="MomentVoice">
            <i className="fas fa-microphone MomentVoice-icon" aria-hidden="true" />
            <audio controls preload="none" src={a.url} aria-label={t('media.voice_note')} />
            {a.duration ? <span className="MomentVoice-length">{duration(a.duration)}</span> : null}
          </div>
        ))}
      </div>
    );
  }

  grid(items: MediaItem[]) {
    const carousel = items.length > 4;
    const single = items.length === 1 ? items[0] : null;
    const ratio = single && single.width && single.height ? Math.max(0.5, Math.min(1.9, single.width / single.height)) : null;

    return (
      <div
        className={`MomentMedia-grid MomentMedia-grid--${carousel ? 'carousel' : items.length}`}
        style={ratio ? { aspectRatio: String(ratio) } : undefined}
      >
        {items.map((item, i) => (
          <div className="MomentMedia-cell" key={item.id}>
            {this.item(item, items, i)}
          </div>
        ))}
      </div>
    );
  }

  item(item: MediaItem, items: MediaItem[], index: number) {
    if (item.kind === 'video' || (item.kind === 'gif' && item.mime === 'video/mp4')) {
      return item.kind === 'gif' ? (
        <video src={item.url} autoplay loop muted playsinline preload="metadata" />
      ) : (
        <video src={item.url} controls playsinline preload="none" aria-label={t('media.video')} />
      );
    }

    return (
      <button type="button" className="MomentMedia-open" onclick={() => openLightbox(items, index)} aria-label={t('media.open')}>
        <img src={item.url} loading="lazy" decoding="async" alt="" width={item.width || undefined} height={item.height || undefined} />
        {item.kind === 'gif' ? <span className="MomentMedia-badge">GIF</span> : null}
      </button>
    );
  }
}
