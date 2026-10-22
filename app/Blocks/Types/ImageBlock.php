<?php

namespace App\Blocks\Types;

use App\Blocks\BlockType;
use App\Rules\MediaUrl;
use App\Services\ContentSanitizer;

class ImageBlock extends BlockType
{
    public function name(): string
    {
        return 'image';
    }

    public function rules(): array
    {
        return [
            'image' => ['required', new MediaUrl],
            'alt' => ['nullable', 'string', 'max:200'],
            'caption' => ['nullable', 'string', 'max:300'],
        ];
    }

    public function attributes(): array
    {
        return [
            'image' => 'image',
            'alt' => 'image alt text',
            'caption' => 'image caption',
        ];
    }

    public function sanitize(array $data, ContentSanitizer $sanitizer): array
    {
        return [
            'image' => (string) $data['image'],
            'alt' => $this->text($data, 'alt'),
            'caption' => $this->text($data, 'caption'),
        ];
    }
}
