<?php

namespace Ernestdefoe\Moments\Tests\integration;

use Flarum\Group\Group;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Psr\Http\Message\ResponseInterface;

/**
 * Members used across the suite:
 *  1 admin, 2 author ("normal"), 3 follower (follows 2), 4 stranger,
 *  5 moderator (Moderators group), 6 a member of a group without Moments
 *  permissions.
 */
abstract class MomentsTestCase extends TestCase
{
    protected bool $follows = true;

    protected function setUp(): void
    {
        parent::setUp();

        $extensions = ['flarum-markdown', 'flarum-mentions'];
        if ($this->follows) {
            array_push($extensions, 'flarum-tags', 'fof-follow-tags', 'ianm-follow-users');
        }
        $extensions[] = 'ernestdefoe-moments';
        $this->extension(...$extensions);
        // Flood control is tested on its own; everywhere else it would only slow the suite.
        $this->setting('ernestdefoe-moments.flood_seconds', 0);

        $users = [];
        foreach ([2 => 'normal', 3 => 'follower', 4 => 'stranger', 5 => 'moderator', 6 => 'muted'] as $id => $name) {
            $users[] = ['id' => $id, 'username' => $name, 'email' => "$name@machine.local", 'is_email_confirmed' => 1, 'password' => 'x'];
        }

        $data = [
            User::class => $users,
            'group_user' => [['user_id' => 5, 'group_id' => Group::MODERATOR_ID]],
        ];
        if ($this->follows) {
            $data['user_followers'] = [['user_id' => 3, 'followed_user_id' => 2, 'subscription' => 'follow']];
        }

        $this->prepareDatabase($data);
    }

    /** @return array<string, mixed> */
    protected function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    /** @param array<string, mixed> $body */
    protected function post(int $as, array $body): ResponseInterface
    {
        return $this->send($this->request('POST', '/api/moments', ['authenticatedAs' => $as, 'json' => $body]));
    }

    /** @param array<string, mixed> $body */
    protected function create(int $as, array $body): int
    {
        $response = $this->post($as, $body);
        $this->assertSame(201, $response->getStatusCode(), substr((string) $response->getBody(), 0, 2000));

        return (int) $this->json($response)['data']['id'];
    }

    protected function get(string $path, ?int $as = null, array $query = []): ResponseInterface
    {
        $request = $this->request('GET', $path, $as ? ['authenticatedAs' => $as] : []);

        return $this->send($query ? $request->withQueryParams($query) : $request);
    }

    /** @return list<int> */
    protected function feedIds(?int $as, array $filter = []): array
    {
        $response = $this->get('/api/moments', $as, ['filter' => $filter]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return array_map(fn ($m) => (int) $m['id'], $this->json($response)['data']);
    }

    /**
     * InnoDB's FULLTEXT index only sees committed rows, and every test runs
     * inside a transaction that is rolled back, so on MySQL/MariaDB a word
     * search in a test finds nothing. Search is asserted on the other
     * databases; on these the query is checked to use the index.
     */
    protected function fulltextInTransaction(): bool
    {
        return in_array($this->database()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * Followers-only moments need flood control out of the way between posts.
     */
    protected function noFlood(): void
    {
        $this->setting('ernestdefoe-moments.flood_seconds', 0);
    }
}
