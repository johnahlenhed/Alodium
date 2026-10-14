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
