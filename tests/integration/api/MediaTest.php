<?php

namespace Ernestdefoe\Moments\Tests\integration\api;

use Ernestdefoe\Moments\Media;
use Ernestdefoe\Moments\Tests\integration\MomentsTestCase;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/** Moments' own upload path: content sniffing, re-encoding, caps and ownership. */
class MediaTest extends MomentsTestCase
{
    protected bool $follows = false;

    protected function upload(int $as, string $bytes, string $name, string $purpose = 'media', array $extra = []): ResponseInterface
    {
        $stream = new Stream('php://temp', 'wb+');
        $stream->write($bytes);
        $stream->rewind();

        $request = $this->request('POST', '/api/moments-media', ['authenticatedAs' => $as])
            ->withUploadedFiles(['file' => new UploadedFile($stream, strlen($bytes), UPLOAD_ERR_OK, $name, 'image/png')])
            ->withParsedBody(['purpose' => $purpose] + $extra);

        return $this->send($request);
    }

    protected function png(int $w = 40, int $h = 30): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 90));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    protected function mp4(string $brand = 'isom'): string
    {
        // ftyp box, then a version-0 mvhd box declaring 1000 units at 1000/s (1 s).
        $ftyp = pack('N', 20).'ftyp'.$brand.pack('N', 0).'isom';
        $mvhd = pack('N', 108).'mvhd'.chr(0).str_repeat("\0", 3).pack('N', 0).pack('N', 0).pack('N', 1000).pack('N', 1000).str_repeat("\0", 80);

        return $ftyp.pack('N', 8 + strlen($mvhd)).'moov'.$mvhd;
    }

    protected function webm(): string
    {
        return "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87\x81\x04".str_repeat("\0", 200);
    }

    #[Test]
    public function an_image_is_sniffed_and_re_encoded_under_a_random_name(): void
    {
        $response = $this->upload(2, $this->png(), 'holiday.php');
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];

        $this->assertSame('image', $data['kind']);
        $this->assertSame('image/webp', $data['mime']);
        $this->assertSame([40, 30], [$data['width'], $data['height']]);
        $this->assertMatchesRegularExpression('#/assets/moments/\d{4}/\d{2}/[0-9a-f]{32}\.webp$#', $data['url']);
        $this->assertStringNotContainsString('holiday', $data['url']);
    }

    #[Test]
    public function svg_html_and_scripts_are_refused_whatever_they_are_called(): void
    {
        $this->assertSame(422, $this->upload(2, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'a.png')->getStatusCode());
        $this->assertSame(422, $this->upload(2, '<html><script>alert(1)</script></html>', 'a.jpg')->getStatusCode());
        $this->assertSame(422, $this->upload(2, '<?php echo 1;', 'a.gif')->getStatusCode());
        // Image magic bytes followed by junk: the decoder refuses it.
        $this->assertSame(422, $this->upload(2, "\x89PNG\r\n\x1A\n<script>alert(1)</script>", 'a.png')->getStatusCode());
        $this->assertSame(0, Media::query()->count());
    }

    #[Test]
    public function video_is_mp4_or_webm_and_size_capped(): void
    {
        $this->assertSame('video/mp4', $this->json($this->upload(2, $this->mp4(), 'clip.mov'))['data']['mime']);
        $this->assertSame('video/webm', $this->json($this->upload(2, $this->webm(), 'clip.mkv'))['data']['mime']);
        // QuickTime isn't on the list.
        $this->assertSame(422, $this->upload(2, $this->mp4('qt  '), 'clip.mov')->getStatusCode());
    }

    #[Test]
    public function the_video_size_cap_applies(): void
    {
        $this->setting('ernestdefoe-moments.video_max_mb', 1);
        $this->setting('ernestdefoe-moments.image_max_kb', 100);

        $this->assertSame(422, $this->upload(2, $this->mp4().str_repeat("\0", 1024 * 1024 + 1), 'big.mp4')->getStatusCode());
    }

    #[Test]
    public function voice_notes_are_checked_for_container_length_and_size(): void
    {
        $this->setting('ernestdefoe-moments.voice_max_seconds', 10);

        $webm = $this->json($this->upload(2, $this->webm(), 'voice.webm', 'voice', ['duration' => 4000]))['data'];
        $this->assertSame(['audio', 'audio/webm', 4000], [$webm['kind'], $webm['mime'], $webm['duration']]);

        // An MP4 declares its own length (1 s here), which wins over what the browser said.
        $this->assertSame(1000, $this->json($this->upload(2, $this->mp4('M4A '), 'voice.m4a', 'voice', ['duration' => 9000]))['data']['duration']);

        $this->assertSame(422, $this->upload(2, $this->webm(), 'voice.webm', 'voice', ['duration' => 60000])->getStatusCode());
        $this->assertSame(422, $this->upload(2, $this->png(), 'voice.webm', 'voice')->getStatusCode());
        $this->assertSame(422, $this->upload(2, $this->webm().str_repeat("\0", 10 * 24 * 1024 + 64 * 1024), 'voice.webm', 'voice')->getStatusCode());
    }

    #[Test]
    public function voice_needs_its_own_permission(): void
    {
        $this->database()->table('group_permission')->where('permission', 'moments.recordVoice')->delete();

        $this->assertSame(403, $this->upload(2, $this->webm(), 'voice.webm', 'voice')->getStatusCode());
        $this->assertSame(201, $this->upload(2, $this->png(), 'photo.png')->getStatusCode());
    }

    #[Test]
    public function a_moment_can_only_claim_your_own_unposted_uploads(): void
    {
        $mine = $this->json($this->upload(2, $this->png(), 'a.png'))['data']['id'];
        $theirs = $this->json($this->upload(3, $this->png(), 'b.png'))['data']['id'];

        $this->assertSame(422, $this->post(2, ['content' => 'steal', 'media' => [$theirs]])->getStatusCode());

        $id = $this->create(2, ['content' => 'mine', 'media' => [$mine]]);
        $this->assertSame($id, Media::query()->find($mine)->entry_id);
        // Already posted: it can't be attached a second time.
        $this->assertSame(422, $this->post(2, ['content' => 'again', 'media' => [$mine]])->getStatusCode());

        $moment = $this->json($this->get("/api/moments/$id", 4))['data'];
        $this->assertCount(1, $moment['media']);
        $this->assertSame('image', $moment['media'][0]['kind']);
    }

    #[Test]
    public function a_voice_note_goes_alone_and_the_media_cap_holds(): void
    {
        $this->setting('ernestdefoe-moments.max_media', 2);
        $voice = $this->json($this->upload(2, $this->webm(), 'v.webm', 'voice'))['data']['id'];
        $photos = [];
        for ($i = 0; $i < 3; $i++) {
            $photos[] = $this->json($this->upload(2, $this->png(), "p$i.png"))['data']['id'];
        }

        $this->assertSame(422, $this->post(2, ['content' => 'x', 'media' => [$voice, $photos[0]]])->getStatusCode());
        $this->assertSame(422, $this->post(2, ['content' => 'x', 'media' => $photos])->getStatusCode());
        $this->assertSame(201, $this->post(2, ['content' => '', 'media' => [$voice]])->getStatusCode());
    }

    #[Test]
    public function deleting_a_moment_deletes_its_files(): void
    {
        $upload = $this->json($this->upload(2, $this->png(), 'a.png'))['data'];
        $id = $this->create(2, ['content' => 'bye', 'media' => [$upload['id']]]);
        $path = Media::query()->find($upload['id'])->path;
        $disk = $this->app()->getContainer()->make(\Ernestdefoe\Moments\Media\Storage::class)->disk();

        $this->assertTrue($disk->exists($path));
        $this->send($this->request('DELETE', "/api/moments/$id", ['authenticatedAs' => 2]));
        $this->assertFalse($disk->exists($path));
        $this->assertSame(0, Media::query()->count());
    }
}
