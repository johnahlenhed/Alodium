<?php

namespace App\Blocks;

use App\Services\ContentSanitizer;

/**
 * One page block type. To add a type: create a subclass in app/Blocks/Types and
 * list it in BlockRegistry::TYPES. Nothing else needs to change.
 */
abstract class BlockType
{
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

    protected function html(array $data, string $key, ContentSanitizer $sanitizer): string
    {
        return $sanitizer->sanitize((string) ($data[$key] ?? ''));
    }
}
