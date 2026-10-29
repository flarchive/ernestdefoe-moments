<?php

namespace Ernestdefoe\Moments\Content;

use Ernestdefoe\Moments\Content;
use Ernestdefoe\Moments\Entry;
use Ernestdefoe\Moments\Visibility;
use Flarum\Extension\ExtensionManager;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A moment's own page in the HTML itself, for link previews and search
 * engines. 🚨 Only a PUBLIC, visible moment says anything here: a
 * followers-only or private one, or a reply, gives nothing away, even to its
 * author's own browser. With fof/seo enabled the Open Graph tags are left to
 * it (see Seo\MomentPage), so they are never written twice.
 */
class MomentDocument
{
    public function __construct(
        protected Visibility $visibility,
        protected Content $content,
        protected UrlGenerator $url,
        protected ExtensionManager $extensions
    ) {
    }

    public static function find(Visibility $visibility, Request $request): ?Entry
    {
        $id = (string) Arr::get($request->getQueryParams(), 'id', '');
        if (! ctype_digit($id) || strlen($id) > 10) {
            return null;
        }

        $entry = $visibility->entries(RequestUtil::getActor($request))->with(['user', 'media'])->find((int) $id);

        return $entry && $entry->isRoot() && $entry->privacy === Entry::PUBLIC && $entry->hidden_at === null ? $entry : null;
    }

    public function __invoke(Document $document, Request $request): Document
    {
        $entry = self::find($this->visibility, $request);
        if (! $entry) {
            return $document;
        }

        $name = (string) $entry->user?->display_name;
        $text = Str::limit($this->content->plain($entry->content), 200);
        $title = $text !== '' ? $name.': '.Str::limit($text, 60) : $name;
        $url = $this->url->to('forum')->route('moments.show', ['id' => $entry->id]);
        $image = $entry->media->first(fn ($m) => in_array($m->kind, ['image', 'gif'], true) && $m->mime !== 'video/mp4');

        $document->title = $title;
        $document->canonicalUrl = $url;
        if ($text !== '') {
            $document->meta['description'] = $text;
        }

        if (! $this->extensions->isEnabled('fof-seo')) {
            $document->head[] = '<meta property="og:type" content="article">';
            $document->head[] = '<meta property="og:title" content="'.e($title).'">';
            $document->head[] = '<meta property="og:url" content="'.e($url).'">';
            if ($text !== '') {
                $document->head[] = '<meta property="og:description" content="'.e($text).'">';
            }
            if ($image) {
                $document->head[] = '<meta property="og:image" content="'.e($image->url).'">';
                $document->head[] = '<meta name="twitter:card" content="summary_large_image">';
            }
        }

        $document->content = '<article><h1>'.e($name).'</h1>'.$this->content->render($entry->content, $entry, $request).'</article>';

        return $document;
    }
}
