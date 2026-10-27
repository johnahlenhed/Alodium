<?php

namespace App\Rules;

use Closure;
use finfo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * A media library upload: JPEG, PNG, GIF, WebP or SVG, judged by its bytes.
 *
 * Laravel's "mimes" rule only looks at the detected type, so a GIF with HTML
 * appended and named "x.html" passed, was stored as x.html and was served as a
 * web page from the admin domain. Here the uploaded name's extension is only a
 * sanity check against the detected type; it never decides how the file is
 * stored. fileName() gives the stored file the extension of the detected type,
 * so it is always served with an image content type.
 */
class ImageUpload implements ValidationRule
{
    /** Detected type => extensions the uploaded name may end in; the first is the stored one. */
    public const TYPES = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'image/svg+xml' => ['svg'],
    ];

    private const LABELS = [
        'image/jpeg' => 'a JPEG',
        'image/png' => 'a PNG',
        'image/gif' => 'a GIF',
        'image/webp' => 'a WebP',
        'image/svg+xml' => 'an SVG',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $type = $value instanceof UploadedFile && $value->isValid() ? self::detectType($value) : null;
        if (! isset(self::TYPES[$type])) {
            $fail('The :attribute must be a JPEG, PNG, GIF, WebP or SVG image.');

            return;
        }

        if (! in_array(strtolower($value->getClientOriginalExtension()), self::TYPES[$type], true)) {
            $endings = implode(' or ', array_map(fn ($extension) => ".{$extension}", self::TYPES[$type]));
            $fail('The :attribute is '.self::LABELS[$type]." image, so its name must end in {$endings}.");
        }
    }

    /** The type from the file's bytes, never from its name or the browser's Content-Type. */
    public static function detectType(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();

        return $path === false ? null : ((new finfo(FILEINFO_MIME_TYPE))->file($path) ?: null);
    }

    /** "Mitt foto (2).JPEG" holding a JPEG -> "mitt-foto-2.jpg": a slug of the name, the type's extension. */
    public static function fileName(UploadedFile $file, string $type): string
    {
        $slug = rtrim(Str::limit(Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)), 80, ''), '-');

        return ($slug !== '' ? $slug : 'image').'.'.self::TYPES[$type][0];
    }
}
