import Component from 'flarum/common/Component';
import type Mithril from 'mithril';
import { topicHref } from '../util';

const TOPIC = /(^|[^\p{L}\p{N}_&/])#((?![pg][0-9]+\b)[-_\p{L}\p{N}\p{M}]{0,29}\p{L}[-_\p{L}\p{N}\p{M}]{0,29})/gu;

/**
 * A moment's text: the HTML the forum's formatter rendered ON THE SERVER from
 * what the member wrote (s9e TextFormatter escapes everything it doesn't
 * recognise), set as the element's content. No string built in the browser
 * from user input is ever inserted as HTML.
 *
 * #topics link to the topic's feed. With ernestdefoe/hashtags they are its
 * hashtag links, re-pointed here; without it, the #words are wrapped in
 * links by walking the text nodes (never by rewriting HTML).
 */
export default class Body extends Component<{ html: string; hashtags: boolean }> {
  html = '';

  view(): Mithril.Children {
    return <div className="Moment-body" onclick={this.onclick.bind(this)} />;
  }

  oncreate(vnode: Mithril.VnodeDOM<{ html: string; hashtags: boolean }, this>) {
    super.oncreate(vnode);
    this.fill();
  }

  onupdate(vnode: Mithril.VnodeDOM<{ html: string; hashtags: boolean }, this>) {
    super.onupdate(vnode);
    if (this.html !== this.attrs.html) this.fill();
  }

  fill() {
    this.html = this.attrs.html;
    (this.element as HTMLElement).innerHTML = this.html;
    if (!this.attrs.hashtags) this.linkTopics(this.element as HTMLElement);
  }

  linkTopics(root: HTMLElement) {
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
      acceptNode: (node) =>
        node.parentElement?.closest('a, code, pre, .PostMention, .UserMention') ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT,
    });
    const nodes: Text[] = [];
    while (walker.nextNode()) {
      if ((walker.currentNode as Text).data.includes('#')) nodes.push(walker.currentNode as Text);
    }

    nodes.forEach((node) => {
      const text = node.data;
      const frag = document.createDocumentFragment();
      let last = 0;
      TOPIC.lastIndex = 0;
      let match: RegExpExecArray | null;
      while ((match = TOPIC.exec(text))) {
        const start = match.index + match[1].length;
        frag.append(text.slice(last, start));
        const a = document.createElement('a');
        a.className = 'MomentTopic';
        a.href = topicHref(match[2]);
        a.textContent = '#' + match[2];
        frag.append(a);
        last = start + match[2].length + 1;
      }
      if (last === 0) return;
      frag.append(text.slice(last));
      node.replaceWith(frag);
    });
  }

  onclick(e: MouseEvent) {
    const link = (e.target as HTMLElement).closest?.('a.Hashtag, a.MomentTopic') as HTMLAnchorElement | null;
    if (!link || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;

    e.preventDefault();
    const key = link.getAttribute('data-hashtag') || (link.textContent || '').replace(/^#/, '');
    m.route.set(topicHref(key));
  }
}
