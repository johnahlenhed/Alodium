<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class MediaTest extends TestCase
{
    use RefreshDatabase;

    private function svg(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>'
        );
    }

    /** A valid 1x1 GIF: GD can decode it, so the conversions succeed. */
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    /**
     * A real file, not UploadedFile::fake(): a fake reports the type of its *name*,
     * so it couldn't show that the type is taken from the bytes.
     */
    private function upload(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'media-test-');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function storedFiles(): array
    {
        return Storage::disk('public')->allFiles();
    }

    public function test_guests_cannot_access_media(): void
    {
        $this->get('/media')->assertRedirect('/login');
    }

    public function test_upload_list_and_delete(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)->post('/media', ['file' => $this->svg()])->assertRedirect();

        $media = Media::firstOrFail();
        $this->assertSame('library', $media->collection_name);
        $this->assertSame($user->id, $media->model_id);
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot());

        $this->actingAs($user)->get('/media')
            ->assertInertia(fn ($page) => $page
                ->component('Media/Index')
                ->has('media', 1)
                ->where('media.0.file_name', 'logo.svg'));

        $this->actingAs($user)->delete("/media/{$media->id}")->assertRedirect();
        $this->assertSame(0, Media::count());
        Storage::disk('public')->assertMissing($media->getPathRelativeToRoot());
    }

    public function test_json_callers_get_the_media_item(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/media', ['file' => $this->svg()])
            ->assertCreated()
            ->assertJsonPath('media.file_name', 'logo.svg')
            ->assertJsonStructure(['media' => ['id', 'url', 'thumb_url', 'human_size', 'created_at']]);
    }

    public function test_validation_rejects_wrong_type_and_oversized_files(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/media', [
            'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])->assertJsonValidationErrors('file');

        $this->actingAs($user)->postJson('/media', [
            'file' => UploadedFile::fake()->create('huge.png', 10241, 'image/png'),
        ])->assertJsonValidationErrors('file');
    }

    // The exploit from the security review: a GIF with HTML appended, named x.html,
    // passed "mimes:gif" and was stored as /storage/{id}/x.html, a web page on the
    // admin domain that an editor could get an admin to open.
    public function test_a_gif_with_html_appended_and_named_html_is_rejected(): void
    {
        Storage::fake('public');
        $editor = User::factory()->editor()->create();
        $polyglot = base64_decode(self::GIF).'<script>alert(document.domain)</script>';

        $this->actingAs($editor)
            ->postJson('/media', ['file' => $this->upload('x.html', $polyglot)])
            ->assertJsonValidationErrors(['file' => 'is a GIF image, so its name must end in .gif']);

        $this->assertSame(0, Media::count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_the_stored_name_always_gets_the_extension_of_the_detected_type(): void
    {
        Storage::fake('public');
        $editor = User::factory()->editor()->create();

        // The same polyglot named .gif passes, but is stored and served as a GIF (image/gif), never as HTML.
        $polyglot = base64_decode(self::GIF).'<script>alert(document.domain)</script>';
        $gif = $this->actingAs($editor)->postJson('/media', ['file' => $this->upload('x.gif', $polyglot)])
            ->assertCreated()->json('media');
        $this->assertSame('x.gif', $gif['file_name']);
        $this->assertSame('image/gif', $gif['mime_type']);
        $this->assertStringEndsWith('/x.gif', $gif['url']);

        // An accepted extension in another spelling is replaced by the type's own one, and the name is slugged.
        $source = UploadedFile::fake()->image('source.jpg', 20, 20); // kept in a variable: its temp file goes with it
        $jpeg = file_get_contents($source->getRealPath());
        $this->actingAs($editor)->postJson('/media', ['file' => $this->upload('Mitt foto (2).JPEG', $jpeg)])
            ->assertCreated()
            ->assertJsonPath('media.file_name', 'mitt-foto-2.jpg')
            ->assertJsonPath('media.name', 'Mitt foto (2)');

        foreach ($this->storedFiles() as $path) {
            $this->assertMatchesRegularExpression('/\.(gif|jpg|webp)$/', $path);
        }
    }

    public function test_the_name_must_match_the_detected_type(): void
    {
        Storage::fake('public');
        $editor = User::factory()->editor()->create();
        $gif = base64_decode(self::GIF);

        foreach (['x.png', 'x.svg', 'x', 'x.gif.html'] as $name) {
            $this->actingAs($editor)->postJson('/media', ['file' => $this->upload($name, $gif)])
                ->assertJsonValidationErrors(['file' => 'must end in .gif']);
        }

        // HTML is not an accepted type at all, whatever it is called.
        $this->actingAs($editor)->postJson('/media', ['file' => $this->upload('x.gif', '<html><script>alert(1)</script></html>')])
            ->assertJsonValidationErrors(['file' => 'must be a JPEG, PNG, GIF, WebP or SVG image']);

        $this->assertSame(0, Media::count());
    }

    // The second exploit: an SVG opened directly runs its scripts on the admin domain.
    public function test_an_svg_is_sanitised_before_it_is_stored(): void
    {
        Storage::fake('public');
        $editor = User::factory()->editor()->create();
        $svg = <<<'SVG'
            <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="10" height="10" onload="alert(1)">
              <script>alert(document.domain)</script>
              <style>@import url(https://evil.example/x.css);</style>
              <rect width="10" height="10" fill="red" onclick="alert(2)"/>
              <a xlink:href="javascript:alert(3)"><text>link</text></a>
              <a href="javascript:alert(4)"><text>link</text></a>
              <foreignObject><iframe xmlns="http://www.w3.org/1999/xhtml" src="javascript:alert(5)"></iframe></foreignObject>
              <image href="https://evil.example/x.png" width="1" height="1"/>
              <use href="https://evil.example/s.svg#a"/>
              <animate attributeName="href" values="javascript:alert(6)"/>
              <set attributeName="onmouseover" to="alert(7)"/>
            </svg>
            SVG;

        $this->actingAs($editor)->postJson('/media', ['file' => $this->upload('Evil.svg', $svg)])->assertCreated();

        $media = Media::firstOrFail();
        $this->assertSame('evil.svg', $media->file_name);
        $this->assertSame('image/svg+xml', $media->mime_type);
        $stored = Storage::disk('public')->get($media->getPathRelativeToRoot());

        foreach (['<script', 'onload', 'onclick', 'onmouseover', 'javascript:', 'foreignObject', 'iframe', 'evil.example', '<animate', '<set'] as $removed) {
            $this->assertStringNotContainsStringIgnoringCase($removed, $stored);
        }
        $this->assertStringContainsString('<rect width="10" height="10" fill="red">', $stored);
    }

    public function test_an_svg_named_otherwise_an_svg_with_entities_and_a_broken_svg_are_rejected(): void
    {
        Storage::fake('public');
        $editor = User::factory()->editor()->create();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        $this->actingAs($editor)->postJson('/media', ['file' => $this->upload('x.html', $svg)])
            ->assertJsonValidationErrors(['file' => 'is an SVG image, so its name must end in .svg']);

        // An internal DTD (entities, XXE) makes the sanitiser refuse the whole file.
        $xxe = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
            .'<svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>';
        $this->actingAs($editor)->postJson('/media', ['file' => $this->upload('x.svg', $xxe)])
            ->assertJsonValidationErrors(['file' => 'could not be read as an SVG image']);

        $this->actingAs($editor)->postJson('/media', ['file' => $this->upload('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><')])
            ->assertJsonValidationErrors(['file' => 'could not be read as an SVG image']);

        $this->assertSame(0, Media::count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_images_get_webp_thumbnail(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('Requires the PHP GD extension for image conversions.');
        }

        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/media', ['file' => UploadedFile::fake()->image('photo.jpg', 1600, 900)])
            ->assertCreated();

        $media = Media::firstOrFail();
        $this->assertTrue($media->hasGeneratedConversion('thumb'));
        $this->assertSame([400, 400], array_slice(getimagesize($media->getPath('thumb')), 0, 2));
        $this->assertTrue($media->hasGeneratedConversion('medium'));
        $this->assertSame(1200, getimagesize($media->getPath('medium'))[0]);
    }
}
