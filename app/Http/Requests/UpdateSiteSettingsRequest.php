<?php

namespace App\Http\Requests;

use App\Models\Page;
use App\Models\SiteSetting;
use App\Rules\BrandColor;
use App\Rules\FooterBackgroundColor;
use App\Rules\NavBackgroundColor;
use App\Rules\NavigationLink;
use App\Services\SvgSanitizer;
use App\Support\LayoutOptions;
use App\Support\SvgSize;
use App\Support\ThemeOptions;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The Settings page form. Text is plain text (stored as typed, trimmed by the
 * TrimStrings middleware); every theme value comes from ThemeOptions.
 */
class UpdateSiteSettingsRequest extends FormRequest
{
    /** Plain text: no control characters (line breaks, tabs, NUL...). Invalid UTF-8 fails too. */
    private const PLAIN_TEXT = 'regex:/^[^\p{Cc}]*\z/u';

    /** Entries after Home: as many as before (12 pages plus Blog and Contact). */
    public const MAX_NAVIGATION_ITEMS = 14;

    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'site_name' => ['required', 'string', 'min:1', 'max:80', self::PLAIN_TEXT],
            'site_description' => ['nullable', 'string', 'max:300', self::PLAIN_TEXT],
            'footer_text' => ['nullable', 'string', 'max:200', self::PLAIN_TEXT],
            'show_site_name' => ['required', 'boolean'],
            // Missing keeps the stored height, so a Settings page loaded before this
            // field existed still saves. Out of range is rejected, not clamped. numeric
            // too: "integer" alone takes true as 1, rejected then only because the
            // minimum is 24; numeric rejects booleans whatever the bounds.
            'logo_height' => ['sometimes', 'integer', 'numeric', 'between:'.SiteSetting::LOGO_HEIGHT_MIN.','.SiteSetting::LOGO_HEIGHT_MAX],

            'brand_color' => ['required', new BrandColor],
            // Checked with the brand colour in this same form (it can change in the same save).
            // Missing keeps the stored colour, so a Settings page loaded before this field still saves.
            'nav_background_color' => ['sometimes', 'nullable', new NavBackgroundColor(ThemeOptions::resolveBrand($this->input('brand_color')))],
            'footer_background_color' => ['sometimes', 'nullable', new FooterBackgroundColor(ThemeOptions::resolveBrand($this->input('brand_color')))],
            // One of the fixed arrangements. Missing keeps the stored one, as above.
            'header_layout' => ['sometimes', 'string', Rule::in(array_keys(LayoutOptions::HEADER))],
            // Missing keeps the stored padding, as above; null clears it (the default).
            // numeric too, as for the logo height.
            'header_padding' => ['sometimes', 'nullable', 'integer', 'numeric', 'between:'.SiteSetting::HEADER_PADDING_MIN.','.SiteSetting::HEADER_PADDING_MAX],
            'footer_layout' => ['sometimes', 'string', Rule::in(array_keys(LayoutOptions::FOOTER))],
            'font_body' => ['required', 'string', Rule::in(array_keys(ThemeOptions::FONTS))],
            'font_heading' => ['required', 'string', Rule::in(array_keys(ThemeOptions::FONTS))],
            'corners' => ['required', 'string', Rule::in(array_keys(ThemeOptions::CORNERS))],
            'buttons' => ['required', 'string', Rule::in(array_keys(ThemeOptions::BUTTONS))],

            // mimetypes is checked against the file's content (finfo), not its name or the
            // type the browser sent, so a renamed PHP or GIF file is rejected. bail: one
            // message. dimensions passes every SVG (Laravel can't measure one): svgLogo() checks it.
            'logo' => ['bail', 'nullable', 'file', 'mimetypes:'.implode(',', SiteSetting::LOGO_MIME_TYPES), 'max:2048', 'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000', $this->svgLogo(...)],
            'remove_logo' => ['sometimes', 'boolean'],

            // The entries after Home, in order. Missing means none (a multipart form
            // can't send an empty list). Each is a page or a URL, never both.
            'navigation' => ['nullable', 'array', 'max:'.self::MAX_NAVIGATION_ITEMS],
            'navigation.*' => ['array'],
            'navigation.*.page_id' => ['nullable', 'integer', 'distinct', 'required_without:navigation.*.url', 'prohibits:navigation.*.url'],
            'navigation.*.url' => ['nullable', 'string', new NavigationLink],
            // Optional for a page (its title is shown), required for a URL.
            'navigation.*.label' => ['nullable', 'string', 'max:60', self::PLAIN_TEXT, 'required_with:navigation.*.url'],

            'front_page_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * An SVG logo must survive the sanitiser and say how big it is (a viewBox, or
     * a width and height), or the site would have nothing to size it by.
     * SiteSettingsController::saveLogo() stores the same sanitised version.
     */
    private function svgLogo(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value->getMimeType() !== 'image/svg+xml') {
            return;
        }

        $svg = app(SvgSanitizer::class)->sanitizeFile($value);
        if ($svg === null) {
            $fail('The logo could not be read as an SVG image.');
        } elseif (SvgSize::of($svg) === null) {
            $fail('This SVG has no size, so the site can\'t tell how wide to show it. Give its <svg> element a viewBox (for example viewBox="0 0 240 80") or a width and height in pixels, then upload it again.');
        }
    }

    /**
     * The navigation pages must be on the public site, and so must a newly chosen
     * front page: one query for all of them. The front page already chosen may
     * stay chosen while it isn't published (SiteSettingsController::edit()).
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $navigation = array_filter(array_map(
                fn ($item) => is_array($item) ? ($item['page_id'] ?? null) : null,
                (array) $this->input('navigation', []),
            ), 'is_numeric');
            $frontPage = $this->input('front_page_id');
            $ids = array_unique(array_map('intval', [...$navigation, ...(is_numeric($frontPage) ? [$frontPage] : [])]));
            $public = $ids ? Page::onPublicSite()->whereIn('id', $ids)->pluck('id')->all() : [];

            foreach ($navigation as $index => $id) {
                if (! in_array((int) $id, $public, true)) {
                    $validator->errors()->add("navigation.$index.page_id", 'Only published pages can be in the navigation.');
                }
            }
            $unchanged = is_numeric($frontPage) && (int) $frontPage === SiteSetting::current()->front_page_id;
            if (is_numeric($frontPage) && ! $unchanged && ! in_array((int) $frontPage, $public, true)) {
                $validator->errors()->add('front_page_id', 'The front page must be a published page.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'site_name.regex' => "The site name can't contain line breaks, tabs or other control characters.",
            'site_description.regex' => "The description can't contain line breaks, tabs or other control characters.",
            'footer_text.regex' => "The footer text can't contain line breaks, tabs or other control characters.",
            'header_layout.*' => 'Choose one of the header layouts.',
            'footer_layout.*' => 'Choose one of the footer layouts.',
            'logo.file' => 'The logo must be an image file.',
            'logo_height.integer' => 'The logo height must be a whole number of pixels.',
            'logo_height.numeric' => 'The logo height must be a whole number of pixels.',
            'logo_height.between' => 'The logo height must be between :min and :max pixels.',
            'header_padding.integer' => 'The header padding must be a whole number of pixels.',
            'header_padding.numeric' => 'The header padding must be a whole number of pixels.',
            'header_padding.between' => 'The header padding must be between :min and :max pixels.',
            'logo.mimetypes' => 'The logo must be a PNG, JPEG, WebP or SVG image. GIF is not supported.',
            'logo.max' => 'The logo can be at most 2 MB.',
            'logo.dimensions' => 'The logo must be between 64 and 4000 pixels wide and high.',
            'navigation.max' => 'The navigation can hold at most :max links after Home.',
            'navigation.*.page_id.distinct' => 'A page can only be in the navigation once.',
            'navigation.*.page_id.required_without' => 'Choose a page or enter a link.',
            'navigation.*.page_id.prohibits' => 'An entry links to a page or to a URL, not both.',
            'navigation.*.label.required_with' => 'A link needs a label.',
            'navigation.*.label.regex' => "The label can't contain line breaks, tabs or other control characters.",
        ];
    }

    public function attributes(): array
    {
        return [
            'site_name' => 'site name',
            'site_description' => 'description',
            'footer_text' => 'footer text',
            'brand_color' => 'brand colour',
            'font_body' => 'body font',
            'font_heading' => 'heading font',
            'front_page_id' => 'front page',
            'navigation.*.page_id' => 'page',
            'navigation.*.url' => 'link',
            'navigation.*.label' => 'label',
        ];
    }
}
