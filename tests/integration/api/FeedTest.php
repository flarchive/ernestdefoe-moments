<?php

namespace Ernestdefoe\Moments\Tests\integration\api;

use Ernestdefoe\Moments\Tests\integration\MomentsTestCase;
use PHPUnit\Framework\Attributes\Test;

/** Feeds, cursor paging, the polling endpoint, search and topics. */
class FeedTest extends MomentsTestCase
{
    #[Test]
    public function the_following_feed_is_the_people_you_follow_and_yourself(): void
    {
        $byAuthor = $this->create(2, ['content' => 'From the followed']);
        $byStranger = $this->create(4, ['content' => 'From a stranger']);
        $own = $this->create(3, ['content' => 'My own']);

        $this->assertSame([$own, $byAuthor], $this->feedIds(3, ['feed' => 'following']));
        $this->assertNotContains($byStranger, $this->feedIds(3, ['feed' => 'following']));
        $this->assertSame([], $this->feedIds(null, ['feed' => 'following']));
    }

    #[Test]
    public function paging_by_cursor_neither_repeats_nor_skips(): void
    {
        $ids = [];
        for ($i = 0; $i < 25; $i++) {
            $ids[] = $this->create([2, 3, 4][$i % 3], ['content' => "Moment $i"]);
        }

        $first = $this->json($this->get('/api/moments', 4));
        $this->assertCount(20, $first['data']);

        // Something new arrives between pages: the next page is unaffected.
        $this->create(2, ['content' => 'Late arrival']);
        $second = $this->json($this->get('/api/moments', 4, ['page' => ['before' => $first['meta']['next']]]));

        $seen = array_merge(array_column($first['data'], 'id'), array_column($second['data'], 'id'));
        $this->assertSame(array_reverse($ids), $seen);
        $this->assertNull($second['meta']['next']);
    }

    #[Test]
    public function the_polling_count_sees_only_newer_moments_by_others(): void
    {
        $first = $this->create(2, ['content' => 'One']);
        $this->create(2, ['content' => 'Two']);
        $this->create(4, ['content' => 'Three']);
        $this->create(3, ['content' => 'Mine']);

        $this->assertSame(2, $this->json($this->get('/api/moments-count', 3, ['after' => $first]))['count']);
        $this->assertSame(0, $this->json($this->get('/api/moments-count', 3, ['after' => 999]))['count']);
        $this->assertSame(3, $this->json($this->get('/api/moments-count', null, ['after' => $first]))['count']);
    }

    #[Test]
    public function search_matches_every_word_and_topics(): void
    {
        $match = $this->create(2, ['content' => 'The quick brown fox #animals']);
        $this->create(2, ['content' => 'A quick blue hare']);
        $topicOnly = $this->create(3, ['content' => 'Look at this #Animals']);

        if ($this->fulltextInTransaction()) {
            $sql = '';
            $this->database()->listen(function ($q) use (&$sql) {
                $sql .= $q->sql;
            });
            $this->feedIds(4, ['q' => 'fox quick']);
            $this->assertStringContainsString('MATCH', $sql);
        } else {
            $this->assertSame([$match], $this->feedIds(4, ['q' => 'fox quick']));
        }
        $this->assertSame([$topicOnly, $match], $this->feedIds(4, ['q' => '#animals']));
        $this->assertSame([$topicOnly, $match], $this->feedIds(4, ['feed' => 'topic', 'topic' => 'ANIMALS']));
        // LIKE wildcards are just characters.
        $this->assertSame([], $this->feedIds(4, ['q' => '%']));
    }

    #[Test]
    public function topics_ignore_code_and_numbers_and_recount_on_edit(): void
    {
        $id = $this->create(2, ['content' => "#one and #two but not `#code` or #123 or C#"]);

        $keys = array_column($this->json($this->get('/api/moments-topics', 4))['data'], 'key');
        sort($keys);
        $this->assertSame(['one', 'two'], $keys);

        $this->send($this->request('PATCH', "/api/moments/$id", ['authenticatedAs' => 2, 'json' => ['content' => 'only #two now']]));
        $this->assertSame(['two'], array_column($this->json($this->get('/api/moments-topics', 4))['data'], 'key'));
    }

    #[Test]
    public function the_profile_feed_needs_a_member_you_can_see(): void
    {
        $this->create(2, ['content' => 'Hello']);

        $this->assertCount(1, $this->feedIds(4, ['feed' => 'user', 'user' => 2]));
        $this->assertSame([], $this->feedIds(4, ['feed' => 'user', 'user' => 999]));
    }
}
