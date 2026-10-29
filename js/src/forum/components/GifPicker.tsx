import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import * as api from '../api';
import { t } from '../util';

interface Attrs {
  onpick: (gif: any) => void;
  onclose: () => void;
}

/** GIPHY search, through the forum's server (which holds the key). Its own lazy chunk. */
export default class GifPicker extends Component<Attrs> {
  q = '';
  results: any[] = [];
  loading = true;
  timer = 0;
  asked = 0;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    this.search();
  }

  oncreate(vnode: Mithril.VnodeDOM<Attrs, this>) {
    super.oncreate(vnode);
    (this.element.querySelector('input') as HTMLInputElement)?.focus();
  }

  search() {
    const ask = ++this.asked;
    this.loading = true;
    api.searchGifs(this.q).then(
      (results) => {
        if (ask !== this.asked) return;
        this.results = results;
        this.loading = false;
        m.redraw();
      },
      () => {
        this.loading = false;
        m.redraw();
      }
    );
  }

  view(): Mithril.Children {
    return (
      <div className="MomentGifPicker" onkeydown={(e: KeyboardEvent) => e.key === 'Escape' && this.attrs.onclose()}>
        <div className="MomentGifPicker-head">
          <input
            className="FormControl"
            type="search"
            placeholder={String(t('gifs.search'))}
            aria-label={String(t('gifs.search'))}
            value={this.q}
            oninput={(e: InputEvent) => {
              this.q = (e.target as HTMLInputElement).value;
              clearTimeout(this.timer);
              this.timer = window.setTimeout(() => this.search(), 350);
            }}
          />
          <button
            type="button"
            className="Button Button--icon Button--flat"
            aria-label={String(t('gifs.close'))}
            onclick={() => this.attrs.onclose()}
          >
            <i className="fas fa-xmark" aria-hidden="true" />
          </button>
        </div>
        <div className="MomentGifPicker-results">
          {this.loading ? (
            <LoadingIndicator size="small" />
          ) : this.results.length ? (
            this.results.map((gif) => (
              <button
                type="button"
                className="MomentGifPicker-item"
                key={gif.id}
                aria-label={gif.title || String(t('gifs.gif'))}
                onclick={() => this.attrs.onpick(gif)}
              >
                <img src={gif.preview} alt="" loading="lazy" />
              </button>
            ))
          ) : (
            <p className="MomentGifPicker-empty">{t('gifs.none')}</p>
          )}
        </div>
        <div className="MomentGifPicker-credit">{t('gifs.credit')}</div>
      </div>
    );
  }
}
