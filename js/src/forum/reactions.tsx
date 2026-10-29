import app from 'flarum/forum/app';
import Icon from 'flarum/common/components/Icon';

/**
 * How a reaction looks. Moments' built-in set, and the emoji names
 * fof/reactions ships with, are drawn as emoji; an icon reaction from
 * fof/reactions as its Font Awesome icon; anything else as its display text.
 */
const EMOJI: Record<string, string> = {
  thumbsup: '👍',
  '+1': '👍',
  thumbsdown: '👎',
  '-1': '👎',
  heart: '❤️',
  laughing: '😆',
  joy: '😂',
  smile: '😄',
  open_mouth: '😮',
  astonished: '😲',
  cry: '😢',
  sob: '😭',
  angry: '😠',
  rage: '😡',
  tada: '🎉',
  confused: '😕',
  fire: '🔥',
  clap: '👏',
  rocket: '🚀',
  eyes: '👀',
  thinking: '🤔',
  hundred: '💯',
  '100': '💯',
  pray: '🙏',
  star: '⭐',
};

export function reactionGlyph(key: string): any {
  if (EMOJI[key]) return <span className="MomentReaction-emoji">{EMOJI[key]}</span>;

  const fof = (app.store.all('reactions') as any[]).find((r: any) => r.identifier?.() === key);
  if (fof && fof.type?.() !== 'emoji') return <Icon name={key} className="MomentReaction-icon" />;
  if (fof && fof.display?.()) return <span className="MomentReaction-emoji">{fof.display()}</span>;

  return <span className="MomentReaction-text">{key}</span>;
}
