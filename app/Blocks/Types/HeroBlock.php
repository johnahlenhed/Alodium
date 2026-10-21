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

    public function rules(): array
    {
        return [
            'heading' => ['required', 'string', 'max:200'],
            'subheading' => ['nullable', 'string', 'max:500'],
            'image' => ['nullable', new MediaUrl],
            'button_label' => ['nullable', 'string', 'max:60'],
            'button_url' => ['nullable', new SafeLink],
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
        ];
    }

    public function plainText(array $data): string
    {
        return trim(($data['heading'] ?? '').' '.($data['subheading'] ?? ''));
    }
}
