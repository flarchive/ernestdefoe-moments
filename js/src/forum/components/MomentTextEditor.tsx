import TextEditor from 'flarum/common/components/TextEditor';

/**
 * The forum's own text editor, so a moment is written exactly as a post is:
 * Markdown, BBCode, emoji and @mention autocomplete, or a rich-text editor
 * if the forum has one. The composer's own submit, preview and rich-text
 * switch are left out; Moments' composer has its own.
 */
export default class MomentTextEditor extends TextEditor {
  controlItems() {
    const items = super.controlItems();

    ['submit', 'preview', 'rich-text'].forEach((key) => items.has(key) && items.remove(key));

    return items;
  }
}
