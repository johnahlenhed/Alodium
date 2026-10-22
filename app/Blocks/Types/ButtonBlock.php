<?php

namespace App\Blocks\Types;

use App\Blocks\BlockType;
use App\Rules\SafeLink;
use App\Services\ContentSanitizer;

class ButtonBlock extends BlockType
{
    public const STYLES = ['primary', 'secondary'];

    public function name(): string
    {
        return 'button';
    }

    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:60'],
            'url' => ['required', new SafeLink],
            'style' => ['nullable', 'in:'.implode(',', self::STYLES)],
        ];
    }

    public function attributes(): array
    {
        return [
            'label' => 'button label',
            'url' => 'button URL',
            'style' => 'button style',
        ];
    }

    public function sanitize(array $data, ContentSanitizer $sanitizer): array
    {
        return [
            'label' => (string) $data['label'],
            'url' => (string) $data['url'],
            'style' => $this->text($data, 'style') ?? 'primary',
        ];
    }
}
