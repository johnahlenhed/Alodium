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

    public function variants(): array
    {
        return self::VARIANTS;
    }

    public function rules(): array
    {
        return [
            'columns' => ['required', 'array', 'size:'.self::COLUMNS],
            'columns.*' => ['array'],
            'columns.*.html' => ['nullable', 'string', 'max:100000'],
            'columns.*.image' => ['nullable', new MediaUrl],
            'columns.*.alt' => ['nullable', 'string', 'max:200'],
            'variant' => $this->variantRule(),
        ];
    }

    public function attributes(): array
    {
        return [
            'columns' => 'columns',
            'columns.*' => 'column :second-position',
            'columns.*.html' => 'column :second-position text',
            'columns.*.image' => 'column :second-position image',
            'columns.*.alt' => 'column :second-position alt text',
            'variant' => 'columns background',
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
            'variant' => $this->variant($data),
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
