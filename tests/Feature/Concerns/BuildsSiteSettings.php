<?php

namespace Tests\Feature\Concerns;

use App\Models\Page;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/** Pages, valid Settings form data and real image files for the site settings tests. */
trait BuildsSiteSettings
{
    /** The Blog and Contact entries, as the navigation_items migration creates them. */
    private const BLOG = ['label' => 'Blog', 'url' => '/blog'];

    private const CONTACT = ['label' => 'Contact', 'url' => '/contact'];

    private ?User $author = null;

    /** Navigation entries for these pages, showing their titles. */
    private function pageEntries(Page ...$pages): array
    {
        return array_map(fn (Page $page) => ['page_id' => $page->id], $pages);
    }

    private function makePage(string $title, array $attributes = []): Page
    {
        $this->author ??= User::factory()->create();

        return Page::create([
            'author_id' => $this->author->id,
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'content' => '<p>Content</p>',
            'status' => 'published',
            'published_at' => now()->subDay(),
            ...$attributes,
        ]);
    }

    /** A complete, valid Settings form submission. */
    private function validSettings(array $overrides = []): array
    {
        return [
            'site_name' => 'Example Studio',
            'site_description' => 'Websites for small businesses.',
            'footer_text' => 'Storgatan 1, Stockholm',
            'show_site_name' => true,
            'brand_color' => '#7A1F3D',
            'font_body' => 'serif',
            'font_heading' => 'inter',
            'corners' => 'round',
            'buttons' => 'outline',
            'navigation' => [],
            'front_page_id' => null,
            ...$overrides,
        ];
    }

    /**
     * A real image file, so the type is read from its content. (UploadedFile::fake()
     * reports whatever type it is given, which would not test the content check.)
     */
    private function image(string $type, int $width, int $height, ?string $name = null, int $padTo = 0): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        // Fully transparent background with an opaque square in the middle.
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, intdiv($width, 4), intdiv($height, 4), intdiv($width * 3, 4), intdiv($height * 3, 4), imagecolorallocatealpha($image, 200, 30, 60, 0));

        $path = tempnam(sys_get_temp_dir(), 'logo');
        match ($type) {
            'png' => imagepng($image, $path),
            'jpeg' => imagejpeg($image, $path),
            'webp' => imagewebp($image, $path),
            'gif' => imagegif($image, $path),
        };

        // Bytes after the end of the image are ignored by image readers: a valid file of any size.
        if ($padTo > 0) {
            file_put_contents($path, str_repeat("\0", max(0, $padTo - filesize($path))), FILE_APPEND);
        }

        return new UploadedFile($path, $name ?? "logo.$type", null, null, true);
    }

    /** A file with the given content and name (its type is guessed from the content). */
    private function fileWithContent(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }
}
