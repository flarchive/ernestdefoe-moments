<?php

namespace Ernestdefoe\Moments\Seo;

use Ernestdefoe\Moments\Content;
use Ernestdefoe\Moments\Content\MomentDocument;
use Ernestdefoe\Moments\Visibility;
use Flarum\Http\UrlGenerator;
use FoF\Seo\Page\PageDriverInterface;
use FoF\Seo\SeoProperties;
use Illuminate\Support\Str;
use Psr\Http\Message\ServerRequestInterface;

/** With fof/seo: a public moment's title, description, image and address, through fof/seo's own tags. */
class MomentPage implements PageDriverInterface
{
    public function __construct(protected Visibility $visibility, protected Content $content, protected UrlGenerator $url)
    {
    }

    public function extensionDependencies(): array
    {
        return ['ernestdefoe-moments'];
    }

    public function handleRoutes(): array
    {
        return ['moments.show'];
    }

    public function handle(ServerRequestInterface $request, SeoProperties $seo): void
    {
        $entry = MomentDocument::find($this->visibility, $request);
        if (! $entry) {
            return;
        }

        $text = Str::limit($this->content->plain($entry->content), 200);
        $name = (string) $entry->user?->display_name;
        $image = $entry->media->first(fn ($m) => in_array($m->kind, ['image', 'gif'], true) && $m->mime !== 'video/mp4');

        $seo->setTitle($text !== '' ? $name.': '.Str::limit($text, 60) : $name)
            ->setDescription($text !== '' ? $text : null)
            ->setUrl($this->url->to('forum')->route('moments.show', ['id' => $entry->id]), false);

        if ($image) {
            $seo->setImage($image->url);
        }
        if ($entry->created_at) {
            $seo->setPublishedOn($entry->created_at->toIso8601String());
        }
    }
}
