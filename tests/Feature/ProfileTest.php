<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        // Another admin must exist to take over the account's content.
        User::factory()->create();
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }

    public function test_deleting_own_account_transfers_content_to_another_admin(): void
    {
        Storage::fake('public');
        $otherAdmin = User::factory()->create();
        $editor = User::factory()->editor()->create();
        $post = Post::create(['author_id' => $editor->id, 'title' => 'P', 'slug' => 'p', 'status' => 'published', 'published_at' => now()]);
        $page = Page::create(['author_id' => $editor->id, 'title' => 'Q', 'slug' => 'q', 'status' => 'draft']);
        $media = $editor->addMedia(UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml'))->toMediaCollection('library');

        $this->actingAs($editor)->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($editor->fresh());
        $this->assertSame($otherAdmin->id, $post->fresh()->author_id);
        $this->assertSame($otherAdmin->id, $page->fresh()->author_id);
        $this->assertSame($otherAdmin->id, (int) Media::findOrFail($media->id)->model_id);
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
    }

    public function test_content_goes_to_the_oldest_other_admin_not_an_editor(): void
    {
        $firstAdmin = User::factory()->create();
        User::factory()->create();
        User::factory()->editor()->create();
        $leaving = User::factory()->create();
        $post = Post::create(['author_id' => $leaving->id, 'title' => 'P', 'slug' => 'p', 'status' => 'draft']);

        $this->actingAs($leaving)->delete('/profile', ['password' => 'password']);

        $this->assertSame($firstAdmin->id, $post->fresh()->author_id);
    }

    public function test_deletion_is_blocked_when_no_other_admin_exists(): void
    {
        $onlyAdmin = User::factory()->create();
        User::factory()->editor()->create(); // editors cannot receive the content
        $post = Post::create(['author_id' => $onlyAdmin->id, 'title' => 'P', 'slug' => 'p', 'status' => 'draft']);

        $this->actingAs($onlyAdmin)
            ->deleteJson('/profile', ['password' => 'password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password' => 'Cannot delete account: no other admin exists to reassign content to.']);

        // From the Inertia form, the same error is shown in the delete dialog.
        $this->actingAs($onlyAdmin)->from('/profile')->delete('/profile', ['password' => 'password'])
            ->assertRedirect('/profile')
            ->assertSessionHasErrors('password');

        $this->assertAuthenticatedAs($onlyAdmin);
        $this->assertNotNull($onlyAdmin->fresh());
        $this->assertNotNull($post->fresh());
    }
}
