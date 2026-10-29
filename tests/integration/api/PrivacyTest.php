<?php

namespace Ernestdefoe\Moments\Tests\integration\api;

use Ernestdefoe\Moments\Broadcaster;
use Ernestdefoe\Moments\Entry;
use Ernestdefoe\Moments\Tests\integration\MomentsTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 🚨 The privacy matrix, on every read path. Author 2 posts one moment of
 * each privacy; 3 follows 2; 4 is a stranger; 5 a moderator; null a guest.
 */
class PrivacyTest extends MomentsTestCase
{
    /** @var array<string, int> */
    protected array $ids = [];

    /** who => [public, followers, private] */
    protected const MATRIX = [
        'author' => [2, true, true, true],
        'follower' => [3, true, true, false],
        'stranger' => [4, true, false, false],
        'moderator' => [5, true, true, false],
        'guest' => [null, true, false, false],
    ];

    protected function seed(): void
    {
        foreach (['public', 'followers', 'private'] as $privacy) {
            $this->ids[$privacy] = $this->create(2, ['content' => "A $privacy moment #secret$privacy", 'privacy' => $privacy]);
        }
    }

    /** @return array<string, bool> */
    protected function expected(string $who): array
    {
        [, $public, $followers, $private] = self::MATRIX[$who];

        return ['public' => $public, 'followers' => $followers, 'private' => $private];
    }

    #[Test]
    public function the_feed_shows_each_reader_only_what_they_may_see(): void
    {
        $this->seed();

        foreach (self::MATRIX as $who => [$as]) {
            $ids = $this->feedIds($as);
            foreach ($this->expected($who) as $privacy => $visible) {
                $this->assertSame($visible, in_array($this->ids[$privacy], $ids, true), "$who / $privacy in feed");
            }
        }
    }

    #[Test]
    public function a_single_moment_is_a_404_to_anyone_who_may_not_see_it(): void
    {
        $this->seed();

        foreach (self::MATRIX as $who => [$as]) {
            foreach ($this->expected($who) as $privacy => $visible) {
                $status = $this->get('/api/moments/'.$this->ids[$privacy], $as)->getStatusCode();
                $this->assertSame($visible ? 200 : 404, $status, "$who / $privacy single");
            }
        }
    }

    #[Test]
    public function the_profile_tab_search_topic_and_count_paths_obey_the_same_rule(): void
    {
        $this->seed();

        foreach (self::MATRIX as $who => [$as]) {
            $profile = $this->feedIds($as, ['feed' => 'user', 'user' => 2]);
            $search = $this->feedIds($as, ['q' => 'moment']);
            foreach ($this->expected($who) as $privacy => $visible) {
                $this->assertSame($visible, in_array($this->ids[$privacy], $profile, true), "$who / $privacy profile");
                if (! $this->fulltextInTransaction()) {
                    $this->assertSame($visible, in_array($this->ids[$privacy], $search, true), "$who / $privacy search");
                }
                $topic = $this->feedIds($as, ['feed' => 'topic', 'topic' => 'secret'.$privacy]);
                $this->assertSame($visible, in_array($this->ids[$privacy], $topic, true), "$who / $privacy topic");
            }

            $count = $this->json($this->get('/api/moments-count', $as, ['after' => 0]))['count'];
            $mine = $as === 2 ? 0 : count(array_filter($this->expected($who)));
            $this->assertSame($mine, $count, "$who new-moments count");
        }
    }

    #[Test]
    public function replies_inherit_their_moments_privacy(): void
    {
        $this->seed();
        $this->create(2, ['content' => 'A reply on followers', 'parentId' => $this->ids['followers']]);

        $this->assertSame(200, $this->get('/api/moments/'.$this->ids['followers'].'/replies', 3)->getStatusCode());
        $this->assertCount(1, $this->json($this->get('/api/moments/'.$this->ids['followers'].'/replies', 3))['data']);
        $this->assertSame(404, $this->get('/api/moments/'.$this->ids['followers'].'/replies', 4)->getStatusCode());
        $this->assertSame(404, $this->get('/api/moments/'.$this->ids['followers'].'/replies')->getStatusCode());

        // A stranger can't reply to what they can't see.
        $this->assertSame(403, $this->post(4, ['content' => 'sneaky', 'parentId' => $this->ids['followers']])->getStatusCode());

        // Changing the moment's privacy carries its replies with it.
        $this->send($this->request('PATCH', '/api/moments/'.$this->ids['followers'], ['authenticatedAs' => 2, 'json' => ['privacy' => 'private']]));
        $this->assertSame(0, Entry::query()->where('root_id', $this->ids['followers'])->where('privacy', '!=', 'private')->count());
    }

    #[Test]
    public function popular_topics_count_only_public_moments(): void
    {
        $this->seed();
        $topics = array_column($this->json($this->get('/api/moments-topics', 4))['data'], 'key');

        $this->assertContains('secretpublic', $topics);
        $this->assertNotContains('secretfollowers', $topics);
        $this->assertNotContains('secretprivate', $topics);
    }

    #[Test]
    public function a_link_card_never_shows_a_moment_its_reader_cannot_open(): void
    {
        $this->seed();
        $url = 'http://localhost/moments/'.$this->ids['followers'];
        $id = $this->create(2, ['content' => "Look: $url", 'privacy' => 'public']);

        $embedsFor = fn (?int $as) => $this->json($this->get('/api/moments/'.$id, $as))['data']['embeds'];

        $this->assertCount(1, $embedsFor(3));
        $this->assertSame('moment', $embedsFor(3)[0]['type']);
        $this->assertSame([], $embedsFor(4));
        $this->assertSame([], $embedsFor(null));
    }

    #[Test]
    public function mentions_notify_only_people_who_can_see_the_moment(): void
    {
        $this->create(2, ['content' => 'Hey @follower and @stranger', 'privacy' => 'followers']);

        $types = fn (int $as) => array_column(array_column($this->json($this->get('/api/notifications', $as))['data'], 'attributes'), 'contentType');

        $this->assertContains('momentMentioned', $types(3));
        $this->assertNotContains('momentMentioned', $types(4));
    }

    #[Test]
    public function a_notification_disappears_when_its_moment_turns_private(): void
    {
        $id = $this->create(2, ['content' => 'Hey @stranger', 'privacy' => 'public']);
        $types = fn () => array_column(array_column($this->json($this->get('/api/notifications', 4))['data'], 'attributes'), 'contentType');
        $this->assertContains('momentMentioned', $types());

        $this->send($this->request('PATCH', '/api/moments/'.$id, ['authenticatedAs' => 2, 'json' => ['privacy' => 'private']]));
        $this->assertNotContains('momentMentioned', $types());
    }

    #[Test]
    public function realtime_pushes_go_only_to_the_audience_and_carry_no_content(): void
    {
        $this->seed();
        $this->app();
        $broadcaster = $this->app()->getContainer()->make(Broadcaster::class);

        $this->assertSame(['public' => true, 'users' => []], $broadcaster->audience(Entry::query()->find($this->ids['public'])));
        $followers = $broadcaster->audience(Entry::query()->find($this->ids['followers']));
        $this->assertFalse($followers['public']);
        $this->assertEqualsCanonicalizing([2, 3], $followers['users']);
        $this->assertSame(['public' => false, 'users' => [2]], $broadcaster->audience(Entry::query()->find($this->ids['private'])));
    }

    #[Test]
    public function the_moment_page_only_describes_public_moments(): void
    {
        $this->seed();

        $public = (string) $this->send($this->request('GET', '/moments/'.$this->ids['public']))->getBody();
        $private = (string) $this->send($this->request('GET', '/moments/'.$this->ids['followers'], ['authenticatedAs' => 3]))->getBody();

        $this->assertStringContainsString('og:title', $public);
        $this->assertStringContainsString('A public moment', $public);
        $this->assertStringNotContainsString('A followers moment', $private);
    }

    #[Test]
    public function bookmarks_are_private_to_their_owner(): void
    {
        $this->seed();
        $this->send($this->request('POST', '/api/moments/'.$this->ids['public'].'/bookmark', ['authenticatedAs' => 3]));

        $this->assertSame([$this->ids['public']], $this->feedIds(3, ['feed' => 'bookmarks']));
        $this->assertSame([], $this->feedIds(4, ['feed' => 'bookmarks']));
        // Bookmarking what you can't see is a 404, not a bookmark.
        $this->assertSame(404, $this->send($this->request('POST', '/api/moments/'.$this->ids['private'].'/bookmark', ['authenticatedAs' => 3]))->getStatusCode());
    }

    #[Test]
    public function replies_to_a_hidden_moment_hide_with_it(): void
    {
        $root = $this->create(2, ['content' => 'About to be hidden']);
        $reply = $this->create(3, ['content' => 'A reply by the follower', 'parentId' => $root]);
        $link = $this->create(4, ['content' => 'See http://localhost/moments/'.$reply]);

        $patch = $this->send($this->request('PATCH', '/api/moments/'.$root, ['authenticatedAs' => 5, 'json' => ['isHidden' => true]]));
        $this->assertSame(200, $patch->getStatusCode());

        // A stranger can no longer reach the reply by its id: no reaction, bookmark, report or link card.
        foreach (['reaction' => ['reaction' => 'heart'], 'bookmark' => [], 'reports' => ['reason' => 'spam']] as $path => $body) {
            $status = $this->send($this->request('POST', "/api/moments/$reply/$path", ['authenticatedAs' => 4, 'json' => $body]))->getStatusCode();
            $this->assertSame(404, $status, "$path on a reply under a hidden moment");
        }
        $this->assertSame([], $this->json($this->get('/api/moments/'.$link, 4))['data']['embeds']);

        // The moment's author and moderators still see the thread.
        foreach ([2, 5] as $as) {
            $this->assertSame([$reply], array_column($this->json($this->get('/api/moments/'.$root.'/replies', $as))['data'], 'id'), "user $as");
        }
    }
}
