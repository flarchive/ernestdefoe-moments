<?php

namespace Ernestdefoe\Moments\Tests\integration\api;

use Ernestdefoe\Moments\Entry;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/** With ernestdefoe/hashtags enabled, a moment's #topics are its hashtags. */
class HashtagsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-mentions', 'ernestdefoe-hashtags', 'ernestdefoe-moments');
        $this->prepareDatabase([User::class => [['id' => 2, 'username' => 'normal', 'email' => 'n@machine.local', 'is_email_confirmed' => 1, 'password' => 'x']]]);
    }

    #[Test]
    public function topics_come_from_the_hashtags_formatter(): void
    {
        $response = $this->send($this->request('POST', '/api/moments', ['authenticatedAs' => 2, 'json' => ['content' => 'Game day #GameDay']]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $data = json_decode((string) $response->getBody(), true)['data'];

        $this->assertStringContainsString('<HASHTAG', (string) Entry::query()->find($data['id'])->content);
        $this->assertStringContainsString('class="Hashtag"', $data['html']);

        $topics = json_decode((string) $this->send($this->request('GET', '/api/moments-topics'))->getBody(), true)['data'];
        $this->assertSame([['name' => 'GameDay', 'key' => 'gameday', 'count' => 1]], $topics);
    }
}
