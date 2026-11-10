<?php

namespace App\Support;

/**
 * How the public site's header and footer are arranged (site_settings.
 * header_layout and footer_layout): a short fixed list per element, picked on
 * the Settings page. Each one is a modifier class in astro/src/styles/site.css
 * and rearranges what is already there; none adds content or changes a colour.
 *
 * The values must equal astro/src/lib/layouts.js (SiteThemeSyncTest), which
 * checks them again in the build.
 */
final class LayoutOptions
{
    /** The arrangement both had before the setting existed. */
    public const DEFAULT = 'standard';

    /** Option => label and the description the Settings page shows under the choice. */
    public const HEADER = [
        'standard' => [
            'label' => 'Logo left, menu right',
            'description' => 'The logo and site name on the left, the menu on the right. On narrow screens the menu wraps onto the lines below.',
        ],
        'centered-menu' => [
            'label' => 'Logo left, menu centred',
            'description' => 'The logo and site name on the left, with the menu in the middle of the header. On screens narrower than 1024 pixels, the menu is centred on a line of its own below them.',
        ],
        'centered' => [
            'label' => 'Logo centred, menu below',
            'description' => 'The logo and site name centred, with the menu centred on a line of its own below them.',
        ],
        'stacked' => [
            'label' => 'Logo left, menu below',
            'description' => 'The logo and site name on the left, with the menu on a line of its own below them. Suits a long menu.',
        ],
    ];

    public const FOOTER = [
        'standard' => [
            'label' => 'Name and menu side by side',
            'description' => 'The site name on the left and the menu on the right, with the footer text below. Stacked on a phone.',
        ],
        'columns' => [
            'label' => 'Three columns',
            'description' => 'The site name, the menu (as a list) and the footer text side by side, on screens at least 720 pixels wide. Narrower screens show the footer as above.',
        ],
        'centered' => [
            'label' => 'Centred',
            'description' => 'Everything centred, one line after another: the site name, the menu, the footer text.',
        ],
    ];

    /**
     * The layout the site uses: the saved one if it is in $options (HEADER or
     * FOOTER), otherwise the default. null (a row from before the columns) and a
     * value changed in the database both mean the default.
     *
     * @param  array<string, array{label: string, description: string}>  $options
     */
    public static function resolve(mixed $value, array $options): string
    {
        return is_string($value) && array_key_exists($value, $options) ? $value : self::DEFAULT;
    }

    /**
     * For the Settings page: [['value' => 'standard', 'label' => …, 'description' => …], …].
     *
     * @param  array<string, array{label: string, description: string}>  $options
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function choices(array $options): array
    {
        return array_map(fn (string $value, array $option) => ['value' => $value, ...$option], array_keys($options), $options);
    }
}
