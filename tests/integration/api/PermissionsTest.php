<?php

namespace Ernestdefoe\Moments\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Moments\Entry;
use Ernestdefoe\Moments\Tests\integration\MomentsTestCase;
use PHPUnit\Framework\Attributes\Test;

/** Group permissions, ownership (IDOR) checks, the edit window and flood control. */
class PermissionsTest extends MomentsTestCase
{
    protected bool $follows = false;

    #[Test]
    public function guests_read_but_never_write(): void
    {
        $id = $this->create(2, ['content' => 'Hello']);

        $this->assertSame([$id], $this->feedIds(null));
        $this->assertContains($this->send($this->request('POST', '/api/moments', ['json' => ['content' => 'x']]))->getStatusCode(), [400, 401]);
        $this->assertContains($this->send($this->request('POST', '/api/moments/'.$id.'/reaction', ['json' => ['reaction' => 'heart']]))->getStatusCode(), [400, 401]);
        $this->assertSame(1, Entry::query()->count());
    }

    #[Test]
    public function without_view_permission_nothing_is_readable(): void
    {
        $this->database()->table('group_permission')->where('permission', 'moments.view')->delete();

        $this->assertSame(403, $this->get('/api/moments', 2)->getStatusCode());
        $this->assertSame(403, $this->get('/api/moments/1', null)->getStatusCode());
        $this->assertSame(403, $this->post(2, ['content' => 'x'])->getStatusCode());
    }

    #[Test]
    public function each_writing_permission_is_enforced(): void
    {
        $this->database()->table('group_permission')->whereIn('permission', ['moments.post', 'moments.reply', 'moments.uploadMedia', 'moments.recordVoice', 'moments.useGifs'])->delete();
        // The admin has every permission.
        $id = $this->create(1, ['content' => 'Hello']);

        $this->assertSame(403, $this->post(3, ['content' => 'x'])->getStatusCode());
        $this->assertSame(403, $this->post(3, ['content' => 'x', 'parentId' => $id])->getStatusCode());
        $this->assertSame(403, $this->send($this->request('POST', '/api/moments-media', ['authenticatedAs' => 3, 'json' => ['gif' => ['url' => 'https://media.giphy.com/media/abc/giphy.gif']]]))->getStatusCode());

    }

    #[Test]
    public function granting_one_permission_gives_exactly_that_one(): void
    {
        $this->database()->table('group_permission')->whereIn('permission', ['moments.post', 'moments.uploadMedia'])->delete();
        $id = $this->create(1, ['content' => 'Hello']);

        $this->assertSame(201, $this->post(3, ['content' => 'a reply', 'parentId' => $id])->getStatusCode());
        $this->assertSame(403, $this->post(3, ['content' => 'still no moments'])->getStatusCode());
    }

    #[Test]
    public function gifs_are_off_without_a_key(): void
    {
        $gif = ['gif' => ['url' => 'https://media.giphy.com/media/abc123/giphy.gif', 'width' => 200, 'height' => 100]];

        $this->assertSame(403, $this->send($this->request('POST', '/api/moments-media', ['authenticatedAs' => 3, 'json' => $gif]))->getStatusCode());
        $this->assertSame(403, $this->get('/api/moments-gifs', 3, ['q' => 'cats'])->getStatusCode());
        $this->assertFalse($this->json($this->get('/api/moments-config', 3))['data']['canUseGifs']);
    }

    #[Test]
    public function gifs_need_the_key_and_giphys_own_hosts(): void
    {
        $this->setting('ernestdefoe-moments.giphy_key', 'secret-key');
        $gif = ['gif' => ['url' => 'https://media.giphy.com/media/abc123/giphy.gif', 'width' => 200, 'height' => 100]];

        $this->assertSame(201, $this->send($this->request('POST', '/api/moments-media', ['authenticatedAs' => 3, 'json' => $gif]))->getStatusCode());
        // Only GIPHY's own media hosts, over https.
        foreach (['http://media.giphy.com/media/abc/giphy.gif', 'https://evil.example/media/abc/giphy.gif', 'https://media.giphy.com.evil.example/media/abc/giphy.gif', "javascript:alert(1)//media.giphy.com/media/a/b.gif"] as $url) {
            $this->assertSame(422, $this->send($this->request('POST', '/api/moments-media', ['authenticatedAs' => 3, 'json' => ['gif' => ['url' => $url]]]))->getStatusCode(), $url);
        }

        // 🚨 The key never reaches a browser.
        $this->assertStringNotContainsString('secret-key', (string) $this->get('/api/moments-config', 3)->getBody());
        $this->assertStringNotContainsString('secret-key', (string) $this->get('/api', 3)->getBody());
        $this->assertStringNotContainsString('secret-key', (string) $this->send($this->request('GET', '/'))->getBody());
    }

    #[Test]
    public function only_the_author_edits_and_only_within_the_window(): void
    {
        $id = $this->create(2, ['content' => 'Original']);
        $patch = fn (int $as, array $body) => $this->send($this->request('PATCH', '/api/moments/'.$id, ['authenticatedAs' => $as, 'json' => $body]))->getStatusCode();

        $this->assertSame(403, $patch(3, ['content' => 'Hijacked']));
        $this->assertSame(403, $patch(5, ['content' => 'Moderator rewrite']));
        $this->assertSame(200, $patch(2, ['content' => 'Edited']));

        Entry::query()->whereKey($id)->update(['created_at' => Carbon::now()->subHours(2)]);
        $this->assertSame(403, $patch(2, ['content' => 'Too late']));
    }

    #[Test]
    public function an_edit_window_of_zero_means_any_time(): void
    {
        $this->setting('ernestdefoe-moments.edit_window', 0);
        $id = $this->create(2, ['content' => 'Original']);
        Entry::query()->whereKey($id)->update(['created_at' => Carbon::now()->subYear()]);

        $this->assertSame(200, $this->send($this->request('PATCH', '/api/moments/'.$id, ['authenticatedAs' => 2, 'json' => ['content' => 'Any time']]))->getStatusCode());
    }

    #[Test]
    public function only_the_author_or_a_moderator_deletes(): void
    {
        $id = $this->create(2, ['content' => 'Mine']);
        $delete = fn (int $as, int $id) => $this->send($this->request('DELETE', '/api/moments/'.$id, ['authenticatedAs' => $as]))->getStatusCode();

        $this->assertSame(403, $delete(3, $id));
        $this->assertSame(204, $delete(5, $id));

        $id = $this->create(2, ['content' => 'Mine again']);
        $this->assertSame(204, $delete(2, $id));
        $this->assertSame(0, Entry::query()->count());
    }

    #[Test]
    public function moderators_hide_and_lock_and_members_cannot(): void
    {
        $id = $this->create(2, ['content' => 'Spicy']);
        $patch = fn (int $as, array $body) => $this->send($this->request('PATCH', '/api/moments/'.$id, ['authenticatedAs' => $as, 'json' => $body]))->getStatusCode();

        $this->assertSame(403, $patch(3, ['isHidden' => true]));
        $this->assertSame(403, $patch(3, ['isLocked' => true]));
        $this->assertSame(200, $patch(5, ['isHidden' => true]));

        // Hidden: gone for others, still there for its author and moderators.
        $this->assertSame([], $this->feedIds(3));
        $this->assertSame([$id], $this->feedIds(2));
        $this->assertSame([$id], $this->feedIds(5));

        $this->assertSame(200, $patch(5, ['isHidden' => false]));
        // The author may lock replies on their own moment.
        $this->assertSame(200, $patch(2, ['isLocked' => true]));
        $this->assertSame(403, $this->post(3, ['content' => 'reply', 'parentId' => $id])->getStatusCode());
        $this->assertSame(201, $this->post(5, ['content' => 'mod reply', 'parentId' => $id])->getStatusCode());
    }

    #[Test]
    public function reports_reach_moderators_only(): void
    {
        $id = $this->create(2, ['content' => 'Report me']);

        $this->assertSame(403, $this->send($this->request('POST', '/api/moments/'.$id.'/reports', ['authenticatedAs' => 2, 'json' => ['reason' => 'spam']]))->getStatusCode());
        $this->assertSame(422, $this->send($this->request('POST', '/api/moments/'.$id.'/reports', ['authenticatedAs' => 3, 'json' => ['reason' => 'nope']]))->getStatusCode());
        $this->assertSame(204, $this->send($this->request('POST', '/api/moments/'.$id.'/reports', ['authenticatedAs' => 3, 'json' => ['reason' => 'spam', 'detail' => 'ads']]))->getStatusCode());

        $this->assertSame([], $this->feedIds(3, ['feed' => 'reported']));
        $this->assertSame([$id], $this->feedIds(5, ['feed' => 'reported']));
        $this->assertSame(1, $this->json($this->get('/api/moments/'.$id, 5))['data']['reportCount']);
        $this->assertNull($this->json($this->get('/api/moments/'.$id, 3))['data']['reportCount']);

        $this->assertSame(403, $this->send($this->request('DELETE', '/api/moments/'.$id.'/reports', ['authenticatedAs' => 3]))->getStatusCode());
        $this->assertSame(204, $this->send($this->request('DELETE', '/api/moments/'.$id.'/reports', ['authenticatedAs' => 5]))->getStatusCode());
        $this->assertSame([], $this->feedIds(5, ['feed' => 'reported']));
    }

    #[Test]
    public function followers_only_is_refused_without_follow_users(): void
    {
        $this->assertSame(422, $this->post(2, ['content' => 'x', 'privacy' => 'followers'])->getStatusCode());
        $this->assertSame([], $this->feedIds(2, ['feed' => 'following']));
    }

    #[Test]
    public function the_length_limit_and_empty_moments_are_refused(): void
    {
        $this->setting('ernestdefoe-moments.char_limit', 20);

        $this->assertSame(422, $this->post(2, ['content' => str_repeat('a', 21)])->getStatusCode());
        $this->assertSame(422, $this->post(2, ['content' => '   '])->getStatusCode());
        $this->assertSame(201, $this->post(2, ['content' => str_repeat('a', 20)])->getStatusCode());
    }

    #[Test]
    public function posting_is_flood_controlled(): void
    {
        $this->setting('ernestdefoe-moments.flood_seconds', 30);

        $this->assertSame(201, $this->post(2, ['content' => 'one'])->getStatusCode());
        $this->assertSame(429, $this->post(2, ['content' => 'two'])->getStatusCode());
        // Someone else isn't held up by it.
        $this->assertSame(201, $this->post(3, ['content' => 'three'])->getStatusCode());
    }
}
