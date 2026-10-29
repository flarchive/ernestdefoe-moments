<?php

namespace Ernestdefoe\Moments\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Moments\Tests\integration\MomentsTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 🚨 A page of moments costs the same number of queries for 1 moment or 20,
 * for a guest and for a member, with every feature in play: authors, media,
 * reactions (breakdown and the reader's own), bookmarks, reposts and their
 * sources, mentions, link cards and topics.
 */
class QueryCountTest extends MomentsTestCase
{
    protected function seed(): void
    {
        $now = Carbon::now();
        $db = $this->database();
        $users = [2, 3, 4, 5];

        $ids = [];
        for ($i = 1; $i <= 20; $i++) {
            $ids[$i] = $id = $this->create($users[$i % 4], [
                'content' => "Moment $i with #topic$i, thanks @normal and @follower, see http://localhost/moments/".($ids[1] ?? 1)." and http://localhost/u/stranger",
            ]);
            $db->table('moments_media')->insert([
                ['entry_id' => $id, 'user_id' => $users[$i % 4], 'kind' => 'image', 'source' => 'local', 'url' => "http://localhost/assets/moments/a$i.webp", 'position' => 0, 'created_at' => $now],
                ['entry_id' => $id, 'user_id' => $users[$i % 4], 'kind' => 'gif', 'source' => 'giphy', 'url' => "https://media.giphy.com/media/x$i/giphy.gif", 'position' => 1, 'created_at' => $now],
            ]);
            foreach ([2, 3, 4] as $reactor) {
                $db->table('moments_reactions')->insert(['entry_id' => $id, 'user_id' => $reactor, 'reaction' => $reactor === 2 ? 'heart' : 'tada', 'created_at' => $now]);
            }
            $db->table('moments_bookmarks')->insert(['entry_id' => $id, 'user_id' => 3, 'created_at' => $now]);
        }

        // Reposts of earlier moments, with and without a comment.
        for ($i = 1; $i <= 6; $i++) {
            $this->create(3, $i % 2 ? ['repostOf' => $ids[$i]] : ['repostOf' => $ids[$i], 'content' => "Quote $i"]);
        }
    }

    /** Set MOMENTS_QUERY_LOG to a file to keep the counts (a test's own output is swallowed in isolation). */
    protected function log(string $line): void
    {
        if ($file = getenv('MOMENTS_QUERY_LOG')) {
            file_put_contents($file, $line.PHP_EOL, FILE_APPEND);
        }
    }

    protected function queriesFor(int $limit, ?int $as): int
    {
        $queries = 0;
        $this->database()->listen(function () use (&$queries) {
            $queries++;
        });

        $request = $this->request('GET', '/api/moments', $as ? ['authenticatedAs' => $as] : [])
            ->withQueryParams(['page' => ['limit' => $limit]]);

        $this->send($request); // warm: settings, formatter, permissions
        $queries = 0;
        $response = $this->send($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount($limit, $this->json($response)['data']);

        return $queries;
    }

    #[Test]
    public function a_page_of_twenty_costs_the_same_as_a_page_of_one(): void
    {
        $this->seed();

        foreach (['guest' => null, 'member' => 3, 'moderator' => 5] as $who => $as) {
            $one = $this->queriesFor(1, $as);
            $twenty = $this->queriesFor(20, $as);

            $this->log(sprintf('page, %s: 1 moment = %d queries, 20 moments = %d queries', $who, $one, $twenty));
            $this->assertSame($one, $twenty, "$who: 1 vs 20 moments");
        }
    }

    #[Test]
    public function a_thread_of_replies_costs_the_same_however_long(): void
    {
        $root = $this->create(2, ['content' => 'Root']);
        $count = function () use ($root) {
            $queries = 0;
            $this->database()->listen(function () use (&$queries) {
                $queries++;
            });
            $request = $this->request('GET', "/api/moments/$root/replies", ['authenticatedAs' => 3]);
            $this->send($request);
            $queries = 0;
            $this->send($request);

            return $queries;
        };

        $parent = $this->create(3, ['content' => 'First @normal', 'parentId' => $root]);
        $one = $count();

        for ($i = 0; $i < 10; $i++) {
            $parent = $this->create([2, 3, 4][$i % 3], ['content' => "Reply $i @follower", 'parentId' => $i % 2 ? $root : $parent]);
        }
        $many = $count();

        $this->log(sprintf('replies: 1 reply = %d queries, 11 replies = %d queries', $one, $many));
        $this->assertSame($one, $many);
    }
}
