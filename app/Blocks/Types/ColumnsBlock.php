<?php

namespace App\Blocks\Types;

use App\Blocks\BlockType;
use App\Rules\MediaUrl;
use App\Services\ContentSanitizer;
use App\Support\PlainText;

class ColumnsBlock extends BlockType
{
    public const COLUMNS = 2;

    public function name(): string
    {
        return 'columns';
    }

    public function rules(): array
    {
        return [
            'columns' => ['required', 'array', 'size:'.self::COLUMNS],
            'columns.*' => ['array'],
            'columns.*.html' => ['nullable', 'string', 'max:100000'],
            'columns.*.image' => ['nullable', new MediaUrl],
            'columns.*.alt' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function sanitize(array $data, ContentSanitizer $sanitizer): array
    {
        return [
            'columns' => array_map(fn (array $column) => [
                'html' => $this->html($column, 'html', $sanitizer),
                'image' => $this->text($column, 'image'),
                'alt' => $this->text($column, 'alt'),
            ], array_values($data['columns'])),
        ];
    }

    public function plainText(array $data): string
    {
        return trim(implode(' ', array_map(
            fn (array $column) => PlainText::fromHtml($column['html'] ?? ''),
            $data['columns'] ?? [],
        )));
    }
}
