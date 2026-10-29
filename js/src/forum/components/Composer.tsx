import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Avatar from 'flarum/common/components/Avatar';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import * as api from '../api';
import type { Config, MediaItem, MomentData } from '../api';
import { PRIVACY_ICONS, t } from '../util';
import MomentTextEditor from './MomentTextEditor';

export interface ComposerAttrs {
  config: Config;
  parentId?: number;
  repostOf?: MomentData;
  edit?: MomentData;
  compact?: boolean;
  autofocus?: boolean;
  placeholder?: string;
  oncancel?: () => void;
  onposted: (moment: MomentData) => void;
}

type Pending = { key: number; name: string; media?: MediaItem; preview?: string };

let VoiceRecorder: any = null;
let GifPicker: any = null;

/**
 * Writing a moment, a reply, a quote-repost or an edit. Its own lazy chunk:
 * a reader who never writes never downloads it.
 */
export default class Composer extends Component<ComposerAttrs> {
  host: { editor: any } = { editor: null };
  value = '';
  privacy: string = 'public';
  pending: Pending[] = [];
  posting = false;
  generation = 0;
  loadingSource = false;
  recording = false;
  gifs = false;
  focused = false;
  counter = 0;

  oninit(vnode: Mithril.Vnode<ComposerAttrs, this>) {
    super.oninit(vnode);
    this.privacy = this.attrs.config.defaultPrivacy;

    if (this.attrs.edit) {
      this.loadingSource = true;
      api.request('GET', `/moments/${this.attrs.edit.id}`, { params: { source: 1 } }).then((r: any) => {
        this.value = r.data.source;
        this.privacy = r.data.privacy;
        this.loadingSource = false;
        m.redraw();
      });
    }
  }

  get isRoot() {
    return !this.attrs.parentId && !(this.attrs.edit && this.attrs.edit.parentId);
  }

  view(): Mithril.Children {
    const cfg = this.attrs.config;
    const remaining = cfg.charLimit - this.value.trim().length;
    const uploading = this.pending.some((p) => !p.media);
    const hasMedia = this.pending.some((p) => p.media);
    const canSend = !this.posting && !uploading && remaining >= 0 && (this.value.trim() !== '' || hasMedia || !!this.attrs.repostOf);
    const user = app.session.user;

    if (this.loadingSource)
      return (
        <div className="MomentComposer MomentComposer--loading">
          <LoadingIndicator size="small" display="inline" />
        </div>
      );

    return (
      <div className={'MomentComposer' + (this.attrs.compact ? ' MomentComposer--compact' : '') + (this.focused || this.value ? ' is-active' : '')}>
        {this.attrs.compact ? null : (
          <div className="MomentComposer-avatar">
            <Avatar user={user} />
          </div>
        )}
        <div className="MomentComposer-main">
          <div className="MomentComposer-editor" onfocusin={() => (this.focused = true)}>
            <MomentTextEditor
              key={this.generation}
              composer={this.host}
              value={this.value}
              placeholder={
                this.attrs.placeholder ||
                t(this.attrs.parentId ? 'composer.reply_placeholder' : this.attrs.repostOf ? 'composer.quote_placeholder' : 'composer.placeholder')
              }
              onchange={(value: string) => {
                const before = this.value.trim().length;
                this.value = value;
                // The editor's input events are not Mithril's: redraw for the counter and the button.
                if (before !== value.trim().length) m.redraw();
              }}
              onsubmit={() => canSend && this.post()}
              onTextEditorBuilt={() => this.attrs.autofocus && this.host.editor?.focus()}
            />
          </div>

          {this.pending.length ? (
            <div className="MomentComposer-attachments">
              {this.pending.map((p) => (
                <div className="MomentComposer-attachment" key={p.key}>
                  {p.media ? this.thumb(p) : <LoadingIndicator size="small" display="inline" />}
                  <button type="button" className="MomentComposer-detach" aria-label={t('composer.remove_attachment')} onclick={() => this.detach(p)}>
                    <i className="fas fa-xmark" aria-hidden="true" />
                  </button>
                </div>
              ))}
            </div>
          ) : null}

          {this.recording && VoiceRecorder ? (
            <VoiceRecorder
              max={cfg.voiceMaxSeconds}
              oncancel={() => (this.recording = false)}
              ondone={(blob: Blob, ms: number, type: string) => {
                this.recording = false;
                this.upload(blob, 'voice', ms, type.includes('mp4') ? 'voice.m4a' : 'voice.webm');
              }}
            />
          ) : null}

          {this.gifs && GifPicker ? (
            <GifPicker
              onclose={() => (this.gifs = false)}
              onpick={(gif: any) => {
                this.gifs = false;
                this.attachGif(gif);
              }}
            />
          ) : null}

          <div className="MomentComposer-toolbar">
            {this.attrs.edit ? null : this.tools()}
            {this.isRoot && !this.attrs.edit?.parentId ? this.privacySelect() : null}
            <span className={'MomentComposer-count' + (remaining < 0 ? ' is-over' : remaining <= 20 ? ' is-near' : '')} aria-live="polite">
              {remaining <= 50 ? remaining : ''}
            </span>
            {this.attrs.oncancel ? (
              <Button className="Button Button--flat" onclick={() => this.attrs.oncancel!()}>
                {t('composer.cancel')}
              </Button>
            ) : null}
            <Button className="Button Button--primary MomentComposer-post" loading={this.posting} disabled={!canSend} onclick={() => this.post()}>
              {t(
                this.attrs.edit ? 'composer.save' : this.attrs.parentId ? 'composer.reply' : this.attrs.repostOf ? 'composer.repost' : 'composer.post'
              )}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  thumb(p: Pending) {
    const media = p.media!;
    if (media.kind === 'audio')
      return (
        <span className="MomentComposer-voice">
          <i className="fas fa-microphone" aria-hidden="true" /> {t('composer.voice_attached')}
        </span>
      );
    if (media.kind === 'video') return <video src={media.url} muted playsinline preload="metadata" />;

    return <img src={media.url} alt="" />;
  }

  tools() {
    const cfg = this.attrs.config;
    const full = this.pending.length >= cfg.maxMedia;
    const voice = this.pending.some((p) => p.media?.kind === 'audio');

    return (
      <span className="MomentComposer-tools">
        {cfg.canUploadMedia ? (
          <label
            className={'Button Button--icon Button--flat MomentComposer-tool' + (full || voice ? ' disabled' : '')}
            aria-label={t('composer.add_media')}
            title={String(t('composer.add_media'))}
          >
            <i className="far fa-image" aria-hidden="true" />
            <input
              type="file"
              accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm"
              multiple
              hidden
              disabled={full || voice}
              onchange={(e: Event) => {
                const input = e.target as HTMLInputElement;
                Array.from(input.files || [])
                  .slice(0, cfg.maxMedia - this.pending.length)
                  .forEach((file) => this.uploadMedia(file));
                input.value = '';
              }}
            />
          </label>
        ) : null}
        {cfg.canRecordVoice && typeof (window as any).MediaRecorder !== 'undefined' ? (
          <Button
            className="Button Button--icon Button--flat MomentComposer-tool"
            icon="fas fa-microphone"
            aria-label={t('composer.record_voice')}
            title={String(t('composer.record_voice'))}
            disabled={this.pending.length > 0 || this.recording}
            onclick={() => this.openRecorder()}
          />
        ) : null}
        {cfg.canUseGifs ? (
          <Button
            className="Button Button--icon Button--flat MomentComposer-tool MomentComposer-gif"
            aria-label={t('composer.add_gif')}
            title={String(t('composer.add_gif'))}
            disabled={full || voice}
            onclick={() => this.openGifs()}
          >
            <span className="MomentComposer-gifLabel">GIF</span>
          </Button>
        ) : null}
      </span>
    );
  }

  privacySelect() {
    const cfg = this.attrs.config;
    const options: Record<string, any> = { public: t('privacy.public') };
    if (cfg.following) options.followers = t('privacy.followers');
    options.private = t('privacy.private');

    return (
      <span className="MomentComposer-privacy">
        <i className={PRIVACY_ICONS[this.privacy]} aria-hidden="true" />
        <Select
          value={this.privacy}
          options={options}
          onchange={(value: string) => (this.privacy = value)}
          aria-label={t('composer.privacy')}
          className="MomentComposer-privacySelect"
        />
      </span>
    );
  }

  openRecorder() {
    const show = () => {
      this.recording = true;
      m.redraw();
    };
    if (VoiceRecorder) return show();
    import('./VoiceRecorder').then((mod) => {
      VoiceRecorder = mod.default;
      show();
    });
  }

  openGifs() {
    const show = () => {
      this.gifs = !this.gifs;
      m.redraw();
    };
    if (GifPicker) return show();
    import('./GifPicker').then((mod) => {
      GifPicker = mod.default;
      show();
    });
  }

  uploadMedia(file: File) {
    const cfg = this.attrs.config;
    const isVideo = file.type.startsWith('video/');
    if (file.size > (isVideo ? cfg.videoMaxBytes : cfg.imageMaxBytes)) {
      app.alerts.show({ type: 'error' }, t('composer.too_large', { name: file.name }));
      return;
    }

    if (cfg.fofUpload) {
      this.track(file.name, api.uploadThroughFof(file));
    } else {
      this.upload(file, 'media', undefined, file.name);
    }
  }

  upload(blob: Blob, purpose: 'media' | 'voice', duration?: number, name = 'upload') {
    this.track(name, api.uploadFile(blob, purpose, duration, name));
  }

  attachGif(gif: any) {
    this.track(gif.title || 'gif', api.attachGif({ url: gif.url, width: gif.width, height: gif.height }));
  }

  track(name: string, promise: Promise<MediaItem>) {
    const p: Pending = { key: ++this.counter, name };
    this.pending.push(p);
    m.redraw();
    promise.then(
      (media) => {
        p.media = media;
        m.redraw();
      },
      () => {
        this.pending = this.pending.filter((x) => x !== p);
        m.redraw();
      }
    );
  }

  detach(p: Pending) {
    this.pending = this.pending.filter((x) => x !== p);
  }

  post() {
    this.posting = true;
    const body: Record<string, any> = { content: this.value };

    if (this.attrs.edit) {
      if (this.isRoot) body.privacy = this.privacy;
    } else {
      body.media = this.pending.filter((p) => p.media).map((p) => p.media!.id);
      if (this.attrs.parentId) body.parentId = this.attrs.parentId;
      else body.privacy = this.privacy;
      if (this.attrs.repostOf) body.repostOf = this.attrs.repostOf.id;
    }

    api.saveMoment(body, this.attrs.edit?.id).then(
      (r) => {
        this.posting = false;
        this.value = '';
        this.pending = [];
        this.focused = false;
        this.generation++;
        this.attrs.onposted(r.data);
        m.redraw();
      },
      () => {
        this.posting = false;
        m.redraw();
      }
    );
  }
}
