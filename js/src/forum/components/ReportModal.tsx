import app from 'flarum/forum/app';
import FormModal, { IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import * as api from '../api';
import { t } from '../util';

const REASONS = ['spam', 'inappropriate', 'off_topic', 'other'];

/** Report a moment or reply to the moderators. */
export default class ReportModal extends FormModal<IFormModalAttrs & { moment: any }> {
  reason = '';
  detail = '';

  className() {
    return 'MomentReportModal Modal--small';
  }

  title() {
    return t('report.title');
  }

  content(): Mithril.Children {
    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="Form-group">
            {REASONS.map((reason) => (
              <label className="checkbox">
                <input type="radio" name="reason" checked={this.reason === reason} value={reason} onchange={() => (this.reason = reason)} />
                <strong>{t(`report.${reason}`)}</strong>
                <span className="helpText">{t(`report.${reason}_help`)}</span>
              </label>
            ))}
          </div>
          <div className="Form-group">
            <textarea
              className="FormControl"
              rows={3}
              maxlength={1000}
              placeholder={String(t('report.detail'))}
              value={this.detail}
              oninput={(e: InputEvent) => (this.detail = (e.target as HTMLTextAreaElement).value)}
            />
          </div>
          <div className="Form-group">
            <Button type="submit" className="Button Button--primary Button--block" loading={this.loading} disabled={!this.reason}>
              {t('report.submit')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;
    api.report(this.attrs.moment.id, this.reason, this.detail).then(
      () => {
        this.hide();
        app.alerts.show({ type: 'success' }, t('report.done'));
      },
      () => {
        this.loading = false;
        m.redraw();
      }
    );
  }
}
