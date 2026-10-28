<?php

namespace App\Http\Requests;

use App\Models\Page;
use App\Rules\BrandColor;
use App\Rules\NavigationLink;
use App\Support\ThemeOptions;
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

            'brand_color' => ['required', new BrandColor],
            'font_body' => ['required', 'string', Rule::in(array_keys(ThemeOptions::FONTS))],
            'font_heading' => ['required', 'string', Rule::in(array_keys(ThemeOptions::FONTS))],
            'corners' => ['required', 'string', Rule::in(array_keys(ThemeOptions::CORNERS))],
            'buttons' => ['required', 'string', Rule::in(array_keys(ThemeOptions::BUTTONS))],

            // mimetypes is checked against the file's content (finfo), not its name or the
            // type the browser sent, so a renamed PHP or SVG file is rejected. bail: one message.
            'logo' => ['bail', 'nullable', 'file', 'mimetypes:image/png,image/jpeg,image/webp', 'max:2048', 'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000'],
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

    /** The front page and navigation pages must be on the public site: one query for all of them. */
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
            if (is_numeric($frontPage) && ! in_array((int) $frontPage, $public, true)) {
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
            'logo.file' => 'The logo must be an image file.',
            'logo.mimetypes' => 'The logo must be a PNG, JPEG or WebP image. SVG and GIF are not supported.',
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
