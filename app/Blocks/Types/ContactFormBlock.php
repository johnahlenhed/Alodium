<?php

namespace App\Blocks\Types;

use App\Blocks\BlockType;
use App\Services\ContentSanitizer;

/** Places the site's contact form on the page; the form fields themselves are fixed. */
class ContactFormBlock extends BlockType
{
    public function name(): string
    {
        return 'contact_form';
    }

    public function rules(): array
    {
        return [
            'heading' => ['nullable', 'string', 'max:200'],
            'intro' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'heading' => 'contact form heading',
            'intro' => 'contact form intro',
        ];
    }

    public function sanitize(array $data, ContentSanitizer $sanitizer): array
    {
        return [
            'heading' => $this->text($data, 'heading'),
            'intro' => $this->text($data, 'intro'),
        ];
    }

    public function plainText(array $data): string
    {
        return trim(($data['heading'] ?? '').' '.($data['intro'] ?? ''));
    }
}
