<?php

namespace App\Blocks;

use App\Blocks\Types\ButtonBlock;
use App\Blocks\Types\ColumnsBlock;
use App\Blocks\Types\ContactFormBlock;
use App\Blocks\Types\HeroBlock;
use App\Blocks\Types\ImageBlock;
use App\Blocks\Types\TextBlock;
use App\Services\ContentSanitizer;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * All page block types, and the validation and storage of a page's block list.
 *
 * Stored shape (pages.blocks, revisions.blocks):
 *   { "version": 1, "blocks": [ { "id": "...", "type": "hero", "data": { ... } } ] }
 */
class BlockRegistry
{
    /** Adding a block type = one new class + one line here. */
    private const TYPES = [
        HeroBlock::class,
        TextBlock::class,
        ImageBlock::class,
        ColumnsBlock::class,
        ButtonBlock::class,
        ContactFormBlock::class,
    ];

    public const VERSION = 1;

    public const MAX_BLOCKS = 50;

    /** @var array<string, BlockType> */
    private array $types = [];

    public function __construct(private ContentSanitizer $sanitizer)
    {
        foreach (self::TYPES as $class) {
            $type = new $class;
            $this->types[$type->name()] = $type;
        }
    }

    public function get(mixed $name): ?BlockType
    {
        return is_string($name) ? ($this->types[$name] ?? null) : null;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->types);
    }

    /**
     * Validation rules for a block list under $attribute. Per-block data rules
     * depend on each block's type, so they are built from the input, with keys
     * like "blocks.2.data.heading" (the shape the forms show errors in).
     */
    public function rules(mixed $blocks, string $attribute = 'blocks'): array
    {
        $rules = [
            $attribute => ['nullable', 'array', 'max:'.self::MAX_BLOCKS],
            "{$attribute}.*" => ['array'],
            "{$attribute}.*.id" => ['nullable', 'string', 'max:36', 'regex:/^[A-Za-z0-9_-]+$/'],
            "{$attribute}.*.type" => ['bail', 'required', 'string', function (string $key, mixed $value, \Closure $fail) {
                if (! $this->get($value)) {
                    $fail("Unknown block type \"{$value}\".");
                }
            }],
            "{$attribute}.*.data" => ['nullable', 'array'],
        ];

        if (! is_array($blocks) || count($blocks) > self::MAX_BLOCKS) {
            return $rules;
        }

        foreach ($blocks as $index => $block) {
            $type = is_array($block) ? $this->get($block['type'] ?? null) : null;

            foreach ($type?->rules() ?? [] as $field => $fieldRules) {
                $rules["{$attribute}.{$index}.data.{$field}"] = $fieldRules;
            }
        }

        return $rules;
    }

    /** Adds an error for every block id that is used more than once. */
    public function validateUniqueIds(Validator $validator, mixed $blocks, string $attribute = 'blocks'): void
    {
        $validator->after(function (Validator $validator) use ($blocks, $attribute) {
            if (! is_array($blocks)) {
                return;
            }

            $seen = [];
            foreach ($blocks as $index => $block) {
                $id = is_array($block) ? ($block['id'] ?? null) : null;
                if (! is_string($id) || $id === '') {
                    continue;
                }
                if (isset($seen[$id])) {
                    $validator->errors()->add("{$attribute}.{$index}.id", "The block id \"{$id}\" is used more than once.");
                }
                $seen[$id] = true;
            }
        });
    }

    /**
     * The value to store for a validated block list, or null (a legacy page).
     * Missing ids get a UUID; unknown data keys are dropped; HTML is sanitised.
     */
    public function normalize(?array $blocks): ?array
    {
        if ($blocks === null) {
            return null;
        }

        return [
            'version' => self::VERSION,
            'blocks' => array_values(array_map(fn (array $block) => [
                'id' => filled($block['id'] ?? null) ? (string) $block['id'] : (string) Str::uuid(),
                'type' => $block['type'],
                'data' => $this->get($block['type'])->sanitize($block['data'] ?? [], $this->sanitizer),
            ], $blocks)),
        ];
    }

    /** Readable text of a block list: each block's text, in order, space-separated. */
    public function plainText(array $blocks, ?array $onlyTypes = null): string
    {
        $parts = [];
        foreach ($blocks as $block) {
            if ($onlyTypes !== null && ! in_array($block['type'] ?? null, $onlyTypes, true)) {
                continue;
            }
            $text = $this->get($block['type'] ?? null)?->plainText($block['data'] ?? []) ?? '';
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode(' ', $parts);
    }
}
