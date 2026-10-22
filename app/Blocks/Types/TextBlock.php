<?php

namespace App\Blocks\Types;

use App\Blocks\BlockType;
use App\Services\ContentSanitizer;
use App\Support\PlainText;

class TextBlock extends BlockType
{
    public function name(): string
    {
        return 'text';
    }

    public function rules(): array
    {
        return [
            // "present": an empty text block is allowed, a missing html key is not.
            'html' => ['present', 'nullable', 'string', 'max:100000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'html' => 'text',
        ];
    }

    public function sanitize(array $data, ContentSanitizer $sanitizer): array
    {
        return ['html' => $this->html($data, 'html', $sanitizer)];
    }

    public function plainText(array $data): string
    {
        return PlainText::fromHtml($data['html'] ?? '');
    }
}
