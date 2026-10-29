import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import { truncate } from 'flarum/common/utils/string';
import { t } from './util';

/**
 * The four notification types. Small on purpose: the notifications dropdown
 * is on every page, so these are in the every-page bundle.
 */
abstract class MomentNotification extends Notification {
  abstract key(): string;

  href() {
    const subject: any = this.attrs.notification.subject();
    const data: any = this.attrs.notification.content() || {};

    return app.route('moments.show', { id: data.repostId || subject?.id() });
  }

  content() {
    const user = this.attrs.notification.fromUser();
    const data: any = this.attrs.notification.content() || {};
    const others = Math.max(0, (Number(data.count) || 1) - 1);

    return t(`notifications.${this.key()}`, { user, count: others });
  }

  excerpt() {
    const subject: any = this.attrs.notification.subject();

    return subject ? truncate(subject.excerpt() || '', 200) : null;
  }
}

class MentionedNotification extends MomentNotification {
  key() {
    return 'mentioned';
  }
  icon() {
    return 'fas fa-at';
  }
}

class RepliedNotification extends MomentNotification {
  key() {
    return 'replied';
  }
  icon() {
    return 'fas fa-reply';
  }
}

class RepostedNotification extends MomentNotification {
  key() {
    return 'reposted';
  }
  icon() {
    return 'fas fa-retweet';
  }
}

class ReactedNotification extends MomentNotification {
  key() {
    return 'reacted';
  }
  icon() {
    return 'far fa-face-smile';
  }
}

export const notificationTypes: Record<string, any> = {
  momentMentioned: MentionedNotification,
  momentReplied: RepliedNotification,
  momentReposted: RepostedNotification,
  momentReacted: ReactedNotification,
};

export const notificationIcons: Record<string, string> = {
  momentMentioned: 'fas fa-at',
  momentReplied: 'fas fa-reply',
  momentReposted: 'fas fa-retweet',
  momentReacted: 'far fa-face-smile',
};
