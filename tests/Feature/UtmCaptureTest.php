<?php

namespace Tests\Feature;

use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What POST /api/collect keeps of the UTM parameters (App\Support\UtmValue):
 * utm_source, utm_medium and utm_campaign, cleaned and lowercase; never
 * utm_term or utm_content; and never a lost page view because of a UTM value.
 * The tracker's side is resources/js/utils/utm.check.mjs.
 */
class UtmCaptureTest extends TestCase
{
    use RefreshDatabase;

    private function collect(array $utm): TestResponse
    {
        return $this->postJson('/api/collect', ['url' => '/shop/', 'referrer' => 'https://mail.example.com/', 'device_type' => 'mobile', ...$utm]);
    }

    /** The stored UTM columns of the one visit. */
    private function stored(): array
    {
        $this->assertSame(1, Visit::count(), 'The page view was not stored.');

        return Visit::first()->only(['utm_source', 'utm_medium', 'utm_campaign']);
    }

    public function test_the_three_values_are_stored_lowercase(): void
    {
        $this->collect(['utm_source' => ' Newsletter ', 'utm_medium' => 'EMAIL', 'utm_campaign' => 'Autumn   Sale 2026'])->assertOk();

        $this->assertSame(['utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'autumn sale 2026'], $this->stored());
        $this->assertSame('/shop/', Visit::first()->url);
    }

    public function test_newsletter_in_any_case_is_one_source(): void
    {
        foreach (['Newsletter', 'newsletter', 'NEWSLETTER', ' newsLetter'] as $source) {
            $this->collect(['utm_source' => $source])->assertOk();
        }

        $this->assertSame(['newsletter' => 4], Visit::pluck('utm_source')->countBy()->all());
    }

    public function test_utm_term_and_utm_content_are_never_stored(): void
    {
        // Not columns any more (dropped by 2026_11_11_110000), so nothing could keep them.
        $this->assertFalse(Schema::hasColumn('visits', 'utm_term'));
        $this->assertFalse(Schema::hasColumn('visits', 'utm_content'));

        $this->collect(['utm_source' => 'newsletter', 'utm_term' => 'anna@example.com', 'utm_content' => 'subscriber-123456789'])->assertOk();

        $this->assertSame('newsletter', $this->stored()['utm_source']);
        $row = (array) DB::table('visits')->first();
        $this->assertArrayNotHasKey('utm_term', $row);
        $this->assertArrayNotHasKey('utm_content', $row);
        $this->assertStringNotContainsString('anna', json_encode($row));
        $this->assertStringNotContainsString('123456789', json_encode($row));
    }

    public function test_an_overlong_value_is_shortened_and_the_page_view_kept(): void
    {
        // Before: max:255 failed validation, the tracker ignored the 422, and the page view was lost.
        $long = trim(str_repeat('summer sale ', 30)); // 359 characters of short words
        $this->collect(['utm_campaign' => $long, 'utm_source' => str_repeat('a ', 200)])->assertOk();

        $stored = $this->stored();
        $this->assertSame(rtrim(mb_substr($long, 0, 100)), $stored['utm_campaign']);
        $this->assertSame(100, mb_strlen($stored['utm_campaign']));
        $this->assertStringStartsWith('summer sale summer sale', $stored['utm_campaign']);
        $this->assertLessThanOrEqual(100, mb_strlen($stored['utm_source']));
    }

    public static function identifyingValues(): array
    {
        return [
            'an email address' => ['anna.svensson@example.com'],
            'an email address in capitals' => ['ANNA@EXAMPLE.COM'],
            'a subscriber number' => ['subscriber-98765432'],
            'a UUID' => ['550e8400-e29b-41d4-a716-446655440000'],
            'a hash' => ['a8f5f167f44f4964e6c998dee827110c'],
            'a click id' => ['EAIaIQobChMI9ptx3aB8gQMVhc1kCh0z'],
            'a 300-letter word' => [str_repeat('x', 300)],
        ];
    }

    #[DataProvider('identifyingValues')]
    public function test_a_value_that_looks_like_it_identifies_a_person_is_dropped_and_the_page_view_kept(string $value): void
    {
        foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $field) {
            Visit::query()->delete();
            $this->collect(['utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'autumn-sale', $field => $value])->assertOk();

            $stored = $this->stored();
            $this->assertNull($stored[$field], $field);
            // The other two, and the rest of the page view, are kept.
            $this->assertCount(2, array_filter($stored), $field);
            $this->assertSame('mobile', Visit::first()->device_type);
        }
    }

    public function test_a_value_that_is_not_text_is_dropped_and_the_page_view_kept(): void
    {
        // Before: the "string" rule failed and the whole page view was lost.
        foreach ([['newsletter'], ['a' => 'b'], 42, 3.5, true, false] as $value) {
            Visit::query()->delete();
            $this->collect(['utm_source' => $value, 'utm_medium' => 'email'])->assertOk();
            $this->assertSame(['utm_source' => null, 'utm_medium' => 'email', 'utm_campaign' => null], $this->stored(), json_encode($value));
        }
    }

    public function test_a_page_view_without_utm_values_stores_none(): void
    {
        $this->collect([])->assertOk();
        $this->collect(['utm_source' => '', 'utm_medium' => '   ', 'utm_campaign' => null])->assertOk();

        $this->assertSame([[null, null, null], [null, null, null]], Visit::all()->map(fn ($visit) => [$visit->utm_source, $visit->utm_medium, $visit->utm_campaign])->all());
    }

    public function test_the_other_fields_are_still_validated(): void
    {
        // Only the UTM values are lenient; a page view without a url is still refused.
        $this->postJson('/api/collect', ['utm_source' => 'newsletter'])->assertUnprocessable()->assertJsonValidationErrors('url');
        $this->assertSame(0, Visit::count());
    }

    // ------------------------------------------------------------ the migrations

    public function test_the_stored_values_are_cleaned_by_the_data_migration(): void
    {
        $insert = fn (?string $source, ?string $medium, ?string $campaign) => DB::table('visits')->insertGetId([
            'url' => '/', 'utm_source' => $source, 'utm_medium' => $medium, 'utm_campaign' => $campaign, 'created_at' => now(),
        ]);
        $ids = [
            $insert('Newsletter', 'Email', 'Autumn Sale'),
            $insert('anna@example.com', 'email', 'subscriber-98765432'),
            $insert(str_repeat('word ', 60), null, null),
            $insert(null, null, null),
            $insert('google', 'cpc', 'launch'),
        ];

        $migration = require database_path('migrations/2026_11_11_120000_clean_utm_values_in_visits_table.php');
        $migration->up();

        $rows = DB::table('visits')->orderBy('id')->get(['utm_source', 'utm_medium', 'utm_campaign'])->map(fn ($row) => array_values((array) $row))->all();
        $this->assertSame([
            ['newsletter', 'email', 'autumn sale'],
            [null, 'email', null],
            [rtrim(str_repeat('word ', 20)), null, null],
            [null, null, null],
            ['google', 'cpc', 'launch'],
        ], $rows);
        $this->assertSame(5, DB::table('visits')->count(), 'No page view is deleted.');

        // A second run changes nothing.
        $migration->up();
        $this->assertSame($rows, DB::table('visits')->orderBy('id')->get(['utm_source', 'utm_medium', 'utm_campaign'])->map(fn ($row) => array_values((array) $row))->all());
        $this->assertCount(5, $ids);
    }

    public function test_the_column_migration_can_be_rolled_back_but_the_values_stay_deleted(): void
    {
        $migration = require database_path('migrations/2026_11_11_110000_drop_utm_term_and_utm_content_from_visits_table.php');
        $migration->down();
        $this->assertTrue(Schema::hasColumns('visits', ['utm_term', 'utm_content']));

        $migration->up();
        $this->assertFalse(Schema::hasColumn('visits', 'utm_term'));
        $this->assertFalse(Schema::hasColumn('visits', 'utm_content'));
        $this->assertTrue(Schema::hasColumns('visits', ['utm_source', 'utm_medium', 'utm_campaign']));
    }
}
