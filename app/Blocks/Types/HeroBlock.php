<?php

namespace App\Blocks\Types;

use App\Blocks\BlockType;
use App\Rules\MediaUrl;
use App\Rules\SafeLink;
use App\Services\ContentSanitizer;

class HeroBlock extends BlockType
{
    public function name(): string
    {
        return 'hero';
    }

    /** "default" is the brand colour, so there is no separate "brand". */
    public function variants(): array
    {
        return ['default', 'light', 'dark'];
    }

    public function rules(): array
    {
        return [
            'heading' => ['required', 'string', 'max:200'],
            'subheading' => ['nullable', 'string', 'max:500'],
            'image' => ['nullable', new MediaUrl],
            'button_label' => ['nullable', 'string', 'max:60'],
            'button_url' => ['nullable', new SafeLink],
            'variant' => $this->variantRule(),
        ];
    }

    public function attributes(): array
    {
        return [
            'heading' => 'hero heading',
            'subheading' => 'hero subheading',
            'image' => 'hero image',
            'button_label' => 'hero button label',
            'button_url' => 'hero button URL',
            'variant' => 'hero background',
        ];
    }

    public function sanitize(array $data, ContentSanitizer $sanitizer): array
    {
        return [
            'heading' => (string) $data['heading'],
            'subheading' => $this->text($data, 'subheading'),
            'image' => $this->text($data, 'image'),
            'button_label' => $this->text($data, 'button_label'),
            'button_url' => $this->text($data, 'button_url'),
            'variant' => $this->variant($data),
        ];
    }

    public function plainText(array $data): string
    {
        return trim(($data['heading'] ?? '').' '.($data['subheading'] ?? ''));
    }
}
