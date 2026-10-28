<?php

namespace App\Blocks;

use App\Services\ContentSanitizer;

/**
 * One page block type. To add a type: create a subclass in app/Blocks/Types and
 * list it in BlockRegistry::TYPES. Nothing else needs to change.
 */
abstract class BlockType
{
    /**
     * Background treatments (data.variant), styled in astro/src/styles/blocks.css
     * as .block--light, .block--brand and .block--dark. "default" adds none. A
     * type lists the ones it offers in variants(); every colour pair they create
     * is checked by astro/scripts/check-contrast.mjs.
     */
    public const VARIANTS = ['default', 'light', 'brand', 'dark'];

    /** Stored in each block's "type", e.g. "hero". */
    abstract public function name(): string;

    /**
     * Validation rules for the keys inside the block's "data", relative to it,
     * e.g. ['heading' => [...]] or ['columns.*.html' => [...]].
     */
    abstract public function rules(): array;

    /**
     * The data to store, from validated input: only the known keys, with rich
     * text run through the shared sanitizer. Unknown keys are dropped here.
     * Plain text fields are stored as given; whoever renders them must escape them.
     */
    abstract public function sanitize(array $data, ContentSanitizer $sanitizer): array;

    /**
     * Plain-language names for the fields in "data", keyed like rules(), used in
     * validation messages ("The hero heading field is required." instead of
     * "The blocks.0.data.heading field is required."). For nested lists,
     * :second-position is the item's position (e.g. "column 2 text").
     */
    public function attributes(): array
    {
        return [];
    }

    /** The variants this type offers (a subset of VARIANTS), or none. */
    public function variants(): array
    {
        return [];
    }

    /** Readable text, for excerpts and descriptions. */
    public function plainText(array $data): string
    {
        return '';
    }

    /** Nullable string field: '' and missing both become null. */
    protected function text(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }

    /** The rule for data.variant: optional, one of this type's variants. */
    protected function variantRule(): array
    {
        return ['nullable', 'in:'.implode(',', $this->variants())];
    }

    /** The variant to store: the validated value, or "default" when it is missing. */
    protected function variant(array $data): string
    {
        return $this->text($data, 'variant') ?? 'default';
    }

    protected function html(array $data, string $key, ContentSanitizer $sanitizer): string
    {
        return $sanitizer->sanitize((string) ($data[$key] ?? ''));
    }
}
