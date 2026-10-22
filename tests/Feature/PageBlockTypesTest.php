<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageBlockTypesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    private function publishedBlockPage(array $blocks): Page
    {
        return Page::create([
            'author_id' => $this->admin->id, 'title' => 'P', 'slug' => 'p', 'status' => 'published',
            'published_at' => now()->subMinute(), 'content' => '<p>stale</p>',
            'blocks' => ['version' => 1, 'blocks' => $blocks],
        ]);
    }

    private function excerpt(): string
    {
        return $this->getJson('/api/public/pages')->json('0.excerpt');
    }

    public function test_excerpt_includes_the_text_of_both_columns(): void
    {
        $this->publishedBlockPage([
            ['id' => 'c', 'type' => 'columns', 'data' => ['columns' => [
                ['html' => '<p>Left <strong>column</strong></p>', 'image' => null, 'alt' => null],
                ['html' => '<p>Right column</p>', 'image' => '/storage/1/x.jpg', 'alt' => 'X'],
            ]]],
        ]);

        $this->assertSame('Left column Right column', $this->excerpt());
    }

    public function test_excerpt_leaves_out_button_labels(): void
    {
        // A button is a call to action, not page text, so it isn't in the excerpt.
        $this->publishedBlockPage([
            ['id' => 't', 'type' => 'text', 'data' => ['html' => '<p>Intro text</p>']],
            ['id' => 'b', 'type' => 'button', 'data' => ['label' => 'Buy now', 'url' => '/shop', 'style' => 'primary']],
        ]);

        $this->assertSame('Intro text', $this->excerpt());
    }

    public function test_excerpt_includes_the_contact_form_heading_and_intro(): void
    {
        $this->publishedBlockPage([
            ['id' => 'f', 'type' => 'contact_form', 'data' => ['heading' => 'Get in touch', 'intro' => 'We reply within a day.']],
        ]);

        $this->assertSame('Get in touch We reply within a day.', $this->excerpt());
    }

    public function test_validation_messages_use_plain_language_field_names(): void
    {
        $page = $this->publishedBlockPage([]);

        $response = $this->actingAs($this->admin)->put("/pages/{$page->id}", [
            'title' => 'P', 'status' => 'published',
            'blocks' => [
                ['type' => 'hero', 'data' => []],
                ['type' => 'button', 'data' => ['label' => '', 'url' => 'javascript:alert(1)']],
                ['type' => 'columns', 'data' => ['columns' => [['html' => 'a', 'image' => 'ftp://x/y.jpg'], ['html' => 'b']]]],
                ['type' => 'image', 'data' => ['image' => '/storage/1/a.jpg', 'alt' => str_repeat('x', 201)]],
                ['type' => 'contact_form', 'data' => ['heading' => str_repeat('x', 201)]],
            ],
        ]);

        $response->assertSessionHasErrors([
            'blocks.0.data.heading' => 'The hero heading field is required.',
            'blocks.1.data.label' => 'The button label field is required.',
            'blocks.1.data.url' => 'The button URL must be an http(s), mailto: or tel: link, or a path starting with /.',
            'blocks.2.data.columns.0.image' => 'The column 1 image must be an http(s) URL or a path starting with /storage/.',
            'blocks.3.data.alt' => 'The image alt text field must not be greater than 200 characters.',
            'blocks.4.data.heading' => 'The contact form heading field must not be greater than 200 characters.',
        ]);

        // No raw keys leak into any message.
        foreach (session('errors')->getMessages() as $messages) {
            $this->assertStringNotContainsString('blocks.', $messages[0]);
        }
    }

    public function test_plain_language_messages_also_in_json_responses(): void
    {
        $page = $this->publishedBlockPage([]);

        $this->actingAs($this->admin)->putJson("/pages/{$page->id}", [
            'title' => 'P', 'status' => 'published',
            'blocks' => [['type' => 'image', 'data' => []]],
        ])->assertJsonValidationErrors(['blocks.0.data.image' => 'The image field is required.']);
    }
}
