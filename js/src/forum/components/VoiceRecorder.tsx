import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import { duration, t } from '../util';

interface Attrs {
  max: number;
  oncancel: () => void;
  ondone: (blob: Blob, ms: number, type: string) => void;
}

const TYPES = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'];

/**
 * A voice note, recorded in the browser with MediaRecorder: Opus in WebM
 * where the browser has it, AAC in MP4 on Safari. Stops by itself at the
 * forum's length limit; nothing leaves the browser until you attach it.
 */
export default class VoiceRecorder extends Component<Attrs> {
  recorder: MediaRecorder | null = null;
  stream: MediaStream | null = null;
  chunks: Blob[] = [];
  started = 0;
  elapsed = 0;
  timer = 0;
  blob: Blob | null = null;
  preview: string | null = null;
  error: string | null = null;

  oncreate(vnode: Mithril.VnodeDOM<Attrs, this>) {
    super.oncreate(vnode);
    this.start();
  }

  onremove(vnode: Mithril.VnodeDOM<Attrs, this>) {
    super.onremove(vnode);
    this.cleanup();
    if (this.preview) URL.revokeObjectURL(this.preview);
  }

  start() {
    this.blob = null;
    if (this.preview) URL.revokeObjectURL(this.preview);
    this.preview = null;
    this.chunks = [];

    navigator.mediaDevices
      ?.getUserMedia({ audio: true })
      .then((stream) => {
        this.stream = stream;
        const type = TYPES.find((x) => (window as any).MediaRecorder.isTypeSupported?.(x)) || '';
        this.recorder = new MediaRecorder(stream, type ? { mimeType: type, audioBitsPerSecond: 48000 } : undefined);
        this.recorder.ondataavailable = (e) => e.data.size && this.chunks.push(e.data);
        this.recorder.onstop = () => this.stopped();
        this.recorder.start(250);
        this.started = Date.now();
        this.timer = window.setInterval(() => {
          this.elapsed = Date.now() - this.started;
          if (this.elapsed >= this.attrs.max * 1000) this.stop();
          m.redraw();
        }, 200);
        m.redraw();
      })
      .catch(() => {
        this.error = String(t('voice.denied'));
        m.redraw();
      });
  }

  stop() {
    if (this.recorder && this.recorder.state !== 'inactive') this.recorder.stop();
  }

  stopped() {
    this.elapsed = Math.min(Date.now() - this.started, this.attrs.max * 1000);
    const type = this.recorder?.mimeType || 'audio/webm';
    this.blob = new Blob(this.chunks, { type });
    this.preview = URL.createObjectURL(this.blob);
    this.cleanup();
    m.redraw();
  }

  cleanup() {
    clearInterval(this.timer);
    this.stream?.getTracks().forEach((track) => track.stop());
    this.stream = null;
  }

  view(): Mithril.Children {
    if (this.error) {
      return (
        <div className="MomentVoiceRecorder MomentVoiceRecorder--error" role="alert">
          <span>{this.error}</span>
          <Button className="Button Button--flat" onclick={() => this.attrs.oncancel()}>
            {t('voice.close')}
          </Button>
        </div>
      );
    }

    if (this.blob && this.preview) {
      return (
        <div className="MomentVoiceRecorder">
          <audio controls src={this.preview} />
          <span className="MomentVoiceRecorder-time">{duration(this.elapsed)}</span>
          <Button className="Button Button--flat" icon="fas fa-rotate-left" onclick={() => this.start()}>
            {t('voice.again')}
          </Button>
          <Button className="Button Button--flat" onclick={() => this.attrs.oncancel()}>
            {t('voice.discard')}
          </Button>
          <Button className="Button Button--primary" icon="fas fa-check" onclick={() => this.attrs.ondone(this.blob!, this.elapsed, this.blob!.type)}>
            {t('voice.attach')}
          </Button>
        </div>
      );
    }

    return (
      <div className="MomentVoiceRecorder is-recording">
        <span className="MomentVoiceRecorder-dot" aria-hidden="true" />
        <span className="MomentVoiceRecorder-time" aria-live="off">
          {duration(this.elapsed) || '0:00'} / {duration(this.attrs.max * 1000)}
        </span>
        <span className="MomentVoiceRecorder-label">{t('voice.recording')}</span>
        <Button className="Button Button--flat" onclick={() => this.attrs.oncancel()}>
          {t('voice.discard')}
        </Button>
        <Button className="Button Button--primary" icon="fas fa-stop" disabled={!this.recorder} onclick={() => this.stop()}>
          {t('voice.stop')}
        </Button>
      </div>
    );
  }
}
