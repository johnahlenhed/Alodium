<?php

namespace Tests\Feature;

use App\Mail\ContactSubmissionReceived;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SubmissionsAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->editor = User::factory()->editor()->create();
    }

    private function submission(array $attributes = []): Submission
    {
        return Submission::create(['name' => 'Grace', 'email' => 'grace@example.com', 'message' => 'Hello there', ...$attributes]);
    }

    public function test_guests_cannot_see_submissions(): void
    {
        $this->get('/submissions')->assertRedirect('/login');
        $this->get('/submissions/'.$this->submission()->id)->assertRedirect('/login');
    }

    public function test_list_is_newest_first_25_per_page_with_excerpts(): void
    {
        foreach (range(1, 27) as $i) {
            $this->travelTo(now()->addMinute());
            $this->submission(['name' => "Person {$i}", 'message' => $i === 27 ? str_repeat('long ', 50) : "Message {$i}"]);
        }

        $this->actingAs($this->editor)->get('/submissions')->assertInertia(fn ($page) => $page
            ->component('Submissions/Index')
            ->has('submissions.data', 25)
            ->where('submissions.total', 27)
            ->where('submissions.data.0.name', 'Person 27')
            ->where('submissions.data.0.excerpt', fn ($excerpt) => mb_strlen($excerpt) <= 103 && str_ends_with($excerpt, '...'))
            ->where('submissions.data.1.name', 'Person 26')
            ->where('submissions.data.0.read', false)
            ->missing('submissions.data.0.message'));

        $this->actingAs($this->editor)->get('/submissions?page=2')
            ->assertInertia(fn ($page) => $page->has('submissions.data', 2)->where('submissions.data.1.name', 'Person 1'));
    }

    public function test_opening_a_submission_marks_it_read_and_mark_unread_undoes_it(): void
    {
        $submission = $this->submission();

        $this->actingAs($this->editor)->get("/submissions/{$submission->id}")
            ->assertInertia(fn ($page) => $page->component('Submissions/Show')->where('submission.message', 'Hello there'));
        $this->assertTrue($submission->fresh()->read);

        $this->actingAs($this->editor)->patch("/submissions/{$submission->id}/unread")
            ->assertRedirect(route('submissions.index'));
        $this->assertFalse($submission->fresh()->read);
    }

    public function test_only_admins_can_delete(): void
    {
        $submission = $this->submission();

        $this->actingAs($this->editor)->delete("/submissions/{$submission->id}")->assertForbidden();
        $this->assertModelExists($submission);

        $this->actingAs($this->admin)->delete("/submissions/{$submission->id}")->assertRedirect(route('submissions.index'));
        $this->assertModelMissing($submission);
    }

    public function test_script_in_name_and_message_is_never_output_as_html(): void
    {
        $submission = $this->submission([
            'name' => '<script>alert("name")</script>',
            'message' => "<script>alert('msg')</script>\n<img src=x onerror=alert(1)>",
        ]);

        // The props carry the text exactly as sent; React renders it as escaped text.
        $this->actingAs($this->admin)->get("/submissions/{$submission->id}")
            ->assertInertia(fn ($page) => $page
                ->where('submission.name', '<script>alert("name")</script>')
                ->where('submission.message', "<script>alert('msg')</script>\n<img src=x onerror=alert(1)>"));

        // The first page load embeds the props as escaped JSON in an attribute, not as markup.
        foreach (['/submissions', "/submissions/{$submission->id}"] as $url) {
            $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert', $html);
            $this->assertStringNotContainsString('<img src=x', $html);
        }

        // Neither page injects raw HTML.
        foreach (['Index', 'Show'] as $page) {
            $this->assertStringNotContainsString('dangerouslySetInnerHTML', file_get_contents(resource_path("js/Pages/Submissions/{$page}.jsx")));
        }
    }

    public function test_unread_count_is_shared_with_admin_pages(): void
    {
        $this->submission();
        $this->submission();
        $this->submission(['read' => true]);

        $this->actingAs($this->editor)->get('/dashboard')->assertInertia(fn ($page) => $page->where('unreadSubmissions', 2));

        $this->actingAs($this->editor)->get('/submissions/'.Submission::where('read', false)->first()->id);
        $this->actingAs($this->editor)->get('/dashboard')->assertInertia(fn ($page) => $page->where('unreadSubmissions', 1));
    }

    public function test_notification_goes_to_contact_notify_email_when_set(): void
    {
        Mail::fake();
        config(['mail.contact_recipient' => 'inbox@example.com', 'mail.from.address' => 'hello@example.com']);

        $this->postJson('/api/public/contact', ['name' => 'A', 'email' => 'a@example.com', 'message' => 'm'])->assertOk();

        Mail::assertSent(ContactSubmissionReceived::class, fn ($mail) => $mail->hasTo('inbox@example.com') && ! $mail->hasTo('hello@example.com'));
    }

    public function test_notification_falls_back_to_mail_from_address(): void
    {
        Mail::fake();
        config(['mail.contact_recipient' => null, 'mail.from.address' => 'hello@example.com']);

        $this->postJson('/api/public/contact', ['name' => 'A', 'email' => 'a@example.com', 'message' => 'm'])->assertOk();

        Mail::assertSent(ContactSubmissionReceived::class, fn ($mail) => $mail->hasTo('hello@example.com'));
    }

    public function test_empty_contact_notify_email_counts_as_not_set(): void
    {
        // CONTACT_NOTIFY_EMAIL= (empty, as in .env.example) must not become an empty recipient.
        putenv('CONTACT_NOTIFY_EMAIL=');
        $_ENV['CONTACT_NOTIFY_EMAIL'] = $_SERVER['CONTACT_NOTIFY_EMAIL'] = '';
        try {
            $this->assertNull((require config_path('mail.php'))['contact_recipient']);
        } finally {
            putenv('CONTACT_NOTIFY_EMAIL');
            unset($_ENV['CONTACT_NOTIFY_EMAIL'], $_SERVER['CONTACT_NOTIFY_EMAIL']);
        }
    }
}
