<?php

namespace App\Support;

/**
 * Running a list of contrast checks on a background colour, shared by
 * PageBackground and NavBackground. The PHP copy of
 * astro/src/lib/contrastChecks.js, step by step.
 */
final class ContrastChecks
{
    /**
     * [what, rgb, minimum] pairs on a #rrggbb background, in order.
     *
     * @return list<array{what: string, ratio: float, minimum: float, ok: bool}>
     */
    public static function run(array $pairs, string $background): array
    {
        $bg = ColorContrast::parseHex($background);

        return array_map(function (array $pair) use ($bg): array {
            [$what, $colour, $minimum] = $pair;
            $ratio = ColorContrast::ratio($colour, $bg);

            return ['what' => $what, 'ratio' => $ratio, 'minimum' => (float) $minimum, 'ok' => $ratio >= $minimum];
        }, $pairs);
    }

    /** The check furthest below its minimum (the first of equals), or null when all pass. */
    public static function worst(array $checks): ?array
    {
        $worst = null;
        foreach ($checks as $check) {
            if (! $check['ok'] && ($worst === null || $check['ratio'] / $check['minimum'] < $worst['ratio'] / $worst['minimum'])) {
                $worst = $check;
            }
        }

        return $worst;
    }
}
