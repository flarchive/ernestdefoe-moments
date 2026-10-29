<?php

namespace Ernestdefoe\Moments\Tests\integration\api;

use Ernestdefoe\Moments\Entry;
use Ernestdefoe\Moments\Tests\integration\MomentsTestCase;
use PHPUnit\Framework\Attributes\Test;

/** Nested replies, threads, sorting, counts, reposts and reactions. */
class RepliesTest extends MomentsTestCase
{
    protected bool $follows = false;

    #[Test]
    public function replies_nest_and_carry_their_thread(): void
    {
        $root = $this->create(2, ['content' => 'Root']);
        $a = $this->create(3, ['content' => 'A', 'parentId' => $root]);
        $a1 = $this->create(4, ['content' => 'A1', 'parentId' => $a]);
        $a11 = $this->create(2, ['content' => 'A1-1', 'parentId' => $a1]);
        $a111 = $this->create(3, ['content' => 'A1-1-1', 'parentId' => $a11]);
        $b = $this->create(4, ['content' => 'B', 'parentId' => $root]);

        $rows = Entry::query()->whereIn('id', [$a, $a1, $a11, $a111, $b])->get()->keyBy('id');
        $this->assertSame([1, 2, 3, 4, 1], [$rows[$a]->depth, $rows[$a1]->depth, $rows[$a11]->depth, $rows[$a111]->depth, $rows[$b]->depth]);
        $this->assertSame([$a, $a, $a, $a, $b], [$rows[$a]->thread_id, $rows[$a1]->thread_id, $rows[$a11]->thread_id, $rows[$a111]->thread_id, $rows[$b]->thread_id]);
        $this->assertSame(5, Entry::query()->find($root)->reply_count);
        $this->assertSame(1, Entry::query()->find($a)->reply_count);

        // Replies never appear in the feed; they live in their thread.
        $this->assertSame([$root], $this->feedIds(4));

        $page = $this->json($this->get("/api/moments/$root/replies", 4));
        $ids = array_column($page['data'], 'id');
        // Recent: newest thread first, then everything nested under the threads, oldest first.
        $this->assertSame([$b, $a, $a1, $a11, $a111], $ids);

        // A reply's own link opens its moment, focused on the reply.
        $show = $this->json($this->get("/api/moments/$a11", 4));
        $this->assertSame($root, $show['data']['id']);
        $this->assertSame($a11, $show['meta']['focus']);
    }

    #[Test]
    public function top_sorts_threads_by_reactions_and_pages_by_cursor(): void
    {
        $root = $this->create(2, ['content' => 'Root']);
        $quiet = $this->create(3, ['content' => 'Quiet', 'parentId' => $root]);
        $loud = $this->create(4, ['content' => 'Loud', 'parentId' => $root]);
        foreach ([2, 3, 5] as $user) {
            $this->send($this->request('POST', "/api/moments/$loud/reaction", ['authenticatedAs' => $user, 'json' => ['reaction' => 'heart']]));
        }
        $this->send($this->request('POST', "/api/moments/$quiet/reaction", ['authenticatedAs' => 2, 'json' => ['reaction' => 'tada']]));

        $top = $this->json($this->get("/api/moments/$root/replies", 4, ['sort' => 'top']));
        $this->assertSame([$loud, $quiet], array_column($top['data'], 'id'));
        $this->assertSame(3, $top['data'][0]['reactionCount']);
        $this->assertSame([['key' => 'heart', 'count' => 3]], $top['data'][0]['reactions']);

        for ($i = 0; $i < 21; $i++) {
            $this->create(5, ['content' => "r$i", 'parentId' => $root]);
        }
        $first = $this->json($this->get("/api/moments/$root/replies", 4, ['sort' => 'top']));
        $this->assertCount(20, $first['data']);
        $second = $this->json($this->get("/api/moments/$root/replies", 4, ['sort' => 'top', 'page' => ['after' => $first['meta']['next']]]));
        $this->assertCount(3, $second['data']);
        $this->assertSame([], array_intersect(array_column($first['data'], 'id'), array_column($second['data'], 'id')));
    }

    #[Test]
    public function one_reaction_per_member_from_the_allowed_set(): void
    {
        $id = $this->create(2, ['content' => 'React']);
        $react = fn (int $as, string $r) => $this->send($this->request('POST', "/api/moments/$id/reaction", ['authenticatedAs' => $as, 'json' => ['reaction' => $r]]));

        $this->assertSame(422, $react(3, 'poop')->getStatusCode());
        $this->assertSame(200, $react(3, 'heart')->getStatusCode());
        $data = $this->json($react(3, 'tada'))['data'];
        $this->assertSame(1, $data['reactionCount']);
        $this->assertSame('tada', $data['myReaction']);

        $this->send($this->request('DELETE', "/api/moments/$id/reaction", ['authenticatedAs' => 3]));
        $this->assertSame(0, Entry::query()->find($id)->reaction_count);

        // The author hears about reactions, batched into one notification.
        $react(3, 'heart');
        $react(4, 'heart');
        $notes = array_filter($this->json($this->get('/api/notifications', 2))['data'], fn ($n) => $n['attributes']['contentType'] === 'momentReacted');
        $this->assertCount(1, $notes);
        $this->assertSame(2, array_values($notes)[0]['attributes']['content']['count']);
    }

    #[Test]
    public function reposts_share_public_moments_once(): void
    {
        $public = $this->create(2, ['content' => 'Share me']);
        $private = $this->create(2, ['content' => 'Mine only', 'privacy' => 'private']);

        $repost = $this->create(3, ['repostOf' => $public]);
        $this->assertSame(422, $this->post(3, ['repostOf' => $public])->getStatusCode());
        $this->assertSame(403, $this->post(2, ['repostOf' => $private])->getStatusCode());
        $this->assertSame(1, Entry::query()->find($public)->repost_count);

        $quote = $this->create(4, ['repostOf' => $public, 'content' => 'So true']);
        $feed = $this->json($this->get('/api/moments', 5))['data'];
        $byId = array_column($feed, null, 'id');
        $this->assertSame($public, $byId[$repost]['repostOf']['id']);
        $this->assertSame($public, $byId[$quote]['repostOf']['id']);
        $this->assertTrue($this->json($this->get("/api/moments/$public", 3))['data']['reposted']);

        // The author hears about it.
        $types = array_column(array_column($this->json($this->get('/api/notifications', 2))['data'], 'attributes'), 'contentType');
        $this->assertContains('momentReposted', $types);

        // If the source turns private, the repost shows it as unavailable.
        $this->send($this->request('PATCH', "/api/moments/$public", ['authenticatedAs' => 2, 'json' => ['privacy' => 'private']]));
        $byId = array_column($this->json($this->get('/api/moments', 5))['data'], null, 'id');
        $this->assertTrue($byId[$repost]['repostOf']['unavailable']);

        // Deleting the source removes reposts with nothing of their own, keeps quotes.
        $this->send($this->request('DELETE', "/api/moments/$public", ['authenticatedAs' => 2]));
        $this->assertNull(Entry::query()->find($repost));
        $this->assertNotNull(Entry::query()->find($quote));
    }

    #[Test]
    public function replies_and_mentions_notify(): void
    {
        $root = $this->create(2, ['content' => 'Root']);
        $reply = $this->create(3, ['content' => 'Reply mentioning @stranger', 'parentId' => $root]);
        $this->create(4, ['content' => 'Reply to the reply', 'parentId' => $reply]);

        $types = fn (int $as) => array_column(array_column($this->json($this->get('/api/notifications', $as))['data'], 'attributes'), 'contentType');

        $this->assertContains('momentReplied', $types(2));
        $this->assertContains('momentReplied', $types(3));
        $this->assertContains('momentMentioned', $types(4));
        $this->assertStringContainsString('USERMENTION', (string) Entry::query()->find($reply)->content);
    }

    #[Test]
    public function deleting_a_reply_takes_its_replies_and_recounts(): void
    {
        $root = $this->create(2, ['content' => 'Root']);
        $a = $this->create(3, ['content' => 'A', 'parentId' => $root]);
        $this->create(4, ['content' => 'A1', 'parentId' => $a]);
        $this->create(4, ['content' => 'B', 'parentId' => $root]);

        $this->assertSame(204, $this->send($this->request('DELETE', "/api/moments/$a", ['authenticatedAs' => 3]))->getStatusCode());
        $this->assertSame(1, Entry::query()->where('root_id', $root)->count());
        $this->assertSame(1, Entry::query()->find($root)->reply_count);
    }
}
