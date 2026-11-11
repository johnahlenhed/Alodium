<?php

namespace Tests\Unit;

use App\Support\UtmValue;
use PHPUnit\Framework\TestCase;

/**
 * UtmValue::clean() against tests/Fixtures/utm-values.json, the examples the
 * tracker and the link builder must agree on too (resources/js/utils/utm.check.mjs).
 */
class UtmValueTest extends TestCase
{
    private static function fixture(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/utm-values.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_every_example_is_cleaned_as_the_fixture_says(): void
    {
        $cases = self::fixture()['strings'];
        $this->assertGreaterThan(30, count($cases));

        foreach ($cases as ['value' => $value, 'stored' => $stored]) {
            $this->assertSame($stored, UtmValue::clean($value), json_encode($value, JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_anything_but_a_string_is_dropped(): void
    {
        foreach (self::fixture()['notStrings'] as $value) {
            $this->assertNull(UtmValue::clean($value), json_encode($value));
        }
        $this->assertNull(UtmValue::clean("\xC3\x28"), 'invalid UTF-8');
    }

    public function test_a_stored_value_is_never_longer_than_the_limit_and_cleaning_it_again_changes_nothing(): void
    {
        $this->assertSame(100, UtmValue::MAX_LENGTH);
        foreach (self::fixture()['strings'] as ['stored' => $stored]) {
            if ($stored === null) {
                continue;
            }
            $this->assertLessThanOrEqual(UtmValue::MAX_LENGTH, mb_strlen($stored));
            // The data migration cleans stored rows with the same function: a second run changes nothing.
            $this->assertSame($stored, UtmValue::clean($stored));
        }
    }

    public function test_only_source_medium_and_campaign_are_read(): void
    {
        $this->assertSame(['utm_source', 'utm_medium', 'utm_campaign'], UtmValue::FIELDS);

        $values = UtmValue::fromInput([
            'utm_source' => 'Newsletter', 'utm_medium' => 'EMAIL', 'utm_campaign' => 'Autumn Sale',
            'utm_term' => 'anna@example.com', 'utm_content' => 'subscriber-123456789',
        ]);

        $this->assertSame(['utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'autumn sale'], $values);
        $this->assertSame(['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null], UtmValue::fromInput([]));
    }
}
