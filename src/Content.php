<?php

namespace Ernestdefoe\Moments;

use Flarum\Formatter\Formatter;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Collection;
use Psr\Http\Message\ServerRequestInterface;
use s9e\TextFormatter\Utils;

/**
 * What a member writes goes through the forum's own formatter, the one posts
 * use: parsed on save into s9e XML (Markdown, BBCode, emoji, links and
 * @mentions, whatever the forum has enabled) and rendered to safe HTML on
 * read. Nothing typed reaches a page any other way.
 */
class Content
{
    /**
     * A topic: `#` not after a word character, at least one letter, at most
     * 59 characters — the same shape as ernestdefoe/hashtags, so a topic
     * written with or without that extension is the same topic.
     */
    public const TOPIC_REGEX = '/(?<![\p{L}\p{N}_&\/])#(?![pg][0-9]+\b)([-_\p{L}\p{N}\p{M}]{0,29}\p{L}[-_\p{L}\p{N}\p{M}]{0,29})\b/u';

    public function __construct(protected Formatter $formatter)
    {
    }

    public function parse(string $source, Entry $context, User $actor): ?string
    {
        $source = trim($source);

        return $source === '' ? null : $this->formatter->parse($source, $context, $actor);
    }

    public function render(?string $xml, Entry $context, ?ServerRequestInterface $request = null): string
    {
        if ($xml === null || $xml === '') {
            return '';
        }

        try {
            return $this->formatter->render($xml, $context, $request);
        } catch (\Throwable) {
            // A formatter hiccup must not take the feed down: show the text.
            return '<p>'.nl2br(e(Utils::removeFormatting($xml))).'</p>';
        }
    }

    public function source(?string $xml, Entry $context): string
    {
        return $xml === null || $xml === '' ? '' : (string) $this->formatter->unparse($xml, $context);
    }

    public function plain(?string $xml): string
    {
        return $xml === null || $xml === '' ? '' : trim((string) preg_replace('/\s+/u', ' ', Utils::removeFormatting($xml)));
    }

    /** @return list<int> members mentioned with flarum/mentions */
    public function mentionedUserIds(?string $xml): array
    {
        if ($xml === null || ! str_contains($xml, '<USERMENTION')) {
            return [];
        }

        return array_values(array_unique(array_map('intval', Utils::getAttributeValues($xml, 'USERMENTION', 'id'))));
    }

    /**
     * The topics written in a moment, folded and de-duplicated: from
     * ernestdefoe/hashtags' HASHTAG tags when it parsed them, otherwise from
     * the text, skipping code (removeFormatting keeps code text, so code
     * spans and blocks are cut out of the XML first).
     *
     * @return array<string, string> key => name as first written
     */
    public function topics(?string $xml): array
    {
        if ($xml === null || $xml === '') {
            return [];
        }

        if (str_contains($xml, '<HASHTAG')) {
            $names = Utils::getAttributeValues($xml, 'HASHTAG', 'name');
        } else {
            $text = Utils::removeFormatting((string) preg_replace('#<(C|CODE)>.*?</\1>#s', ' ', $xml));
            preg_match_all(self::TOPIC_REGEX, $text, $matches);
            $names = $matches[1];
        }

        $topics = [];
        foreach ($names as $name) {
            $name = mb_substr(trim($name), 0, 59);
            if ($name !== '') {
                $topics[Topic::key($name)] ??= $name;
            }
        }

        return array_slice($topics, 0, 10, true);
    }

    /** @return list<string> the addresses of the links in a moment */
    public function links(?string $xml): array
    {
        if ($xml === null || ! str_contains($xml, '<URL')) {
            return [];
        }

        return array_values(array_unique(Utils::getAttributeValues($xml, 'URL', 'url')));
    }

    /**
     * flarum/mentions looks up the members, posts, groups and tags a text
     * mentions when it is rendered: one query per text, unless its context
     * already carries them. A page of moments is many texts, so every
     * mention on the page is looked up here, once per kind, and handed to
     * each moment. Nothing is queried when nothing is mentioned.
     *
     * @param iterable<Entry> $entries
     */
    public function prime(iterable $entries): void
    {
        $kinds = [
            'USERMENTION' => ['mentionsUsers', User::class, []],
            'POSTMENTION' => ['mentionsPosts', 'Flarum\Post\Post', ['user', 'discussion']],
            'GROUPMENTION' => ['mentionsGroups', 'Flarum\Group\Group', []],
            'TAGMENTION' => ['mentionsTags', 'Flarum\Tags\Tag', []],
        ];

        $ids = [];
        foreach ($entries as $entry) {
            $xml = $entry->content;
            if ($xml !== null && str_contains($xml, 'MENTION')) {
                foreach ($kinds as $tag => $kind) {
                    // Already primed (a moment just written, then presented): not again.
                    if ($entry->relationLoaded($kind[0])) {
                        continue;
                    }
                    foreach (Utils::getAttributeValues($xml, $tag, 'id') as $id) {
                        $ids[$tag][$id] = true;
                    }
                }
            }
        }

        foreach ($ids as $tag => $found) {
            [$relation, $class, $with] = $kinds[$tag];
            if (! class_exists($class)) {
                continue;
            }

            /** @var Collection<int, \Flarum\Database\AbstractModel> $models */
            $models = $class::query()->with($with)->findMany(array_keys($found));

            foreach ($entries as $entry) {
                if (! $entry->relationLoaded($relation)) {
                    $entry->setRelation($relation, $models);
                }
            }
        }
    }
}
