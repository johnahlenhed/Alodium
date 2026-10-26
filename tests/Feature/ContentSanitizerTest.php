<?php

namespace Tests\Feature;

use App\Services\ContentSanitizer;
use Tests\TestCase;

class ContentSanitizerTest extends TestCase
{
    private function clean(string $html): string
    {
        return app(ContentSanitizer::class)->sanitize($html);
    }

    public function test_keeps_everything_the_tiptap_editor_produces(): void
    {
        $html = '<h2>Heading</h2><h3>Sub</h3><p><strong>b</strong> <em>i</em> <s>s</s> <u>u</u> <code>code</code></p>'
            .'<pre><code>block</code></pre><hr><ul><li><p>one</p></li></ul><ol><li>two</li></ol>'
            .'<blockquote><p>quote</p></blockquote>'
            .'<p><a target="_blank" rel="noopener noreferrer" href="https://example.com">link</a> <a href="mailto:a@example.com">mail</a> <a href="tel:123">tel</a> <a href="/about">relative</a></p>'
            .'<img src="https://example.com/a.jpg" alt="A"><img src="/storage/1/b.jpg" alt="B">';

        $out = $this->clean($html);

        foreach (['<h2>', '<h3>', '<strong>', '<em>', '<s>', '<u>', '<code>', '<pre>', '<hr />', '<ul>', '<ol>', '<li>', '<blockquote>'] as $tag) {
            $this->assertStringContainsString($tag, $out);
        }
        $this->assertStringContainsString('<a target="_blank" rel="noopener noreferrer" href="https://example.com">link</a>', $out);
        $this->assertStringContainsString('href="mailto:a&#64;example.com"', $out); // "@" is HTML-encoded, same link
        $this->assertStringContainsString('href="tel:123"', $out);
        $this->assertStringContainsString('href="/about"', $out);
        $this->assertStringContainsString('<img src="https://example.com/a.jpg" alt="A" />', $out);
        $this->assertStringContainsString('<img src="/storage/1/b.jpg" alt="B" />', $out);
    }

    public function test_only_left_center_and_right_text_align_survive(): void
    {
        $this->assertSame('<p style="text-align: center">x</p>', $this->clean('<p style="text-align: center;">x</p>'));
        $this->assertSame('<h2 style="text-align: right">x</h2>', $this->clean('<h2 style="TEXT-ALIGN:Right">x</h2>'));
        $this->assertSame('<p style="text-align: left">x</p>', $this->clean('<p style="color: red; text-align: left; font-size: 40px">x</p>'));
        $this->assertSame('<p>x</p>', $this->clean('<p style="text-align: justify">x</p>'));
        $this->assertSame('<p>x</p>', $this->clean('<p style="color: red">x</p>'));
        // Only the text-align declaration is kept; everything else in the style goes.
        $this->assertSame('<p style="text-align: center">x</p>', $this->clean('<p style="text-align: center; background: url(javascript:alert(1))">x</p>'));
        // style is only allowed on paragraphs and headings
        $this->assertSame('<ul><li>x</li></ul>', $this->clean('<ul style="text-align: center"><li style="text-align: center">x</li></ul>'));
    }

    public function test_removes_scripts_event_handlers_and_javascript_links(): void
    {
        $out = $this->clean(
            '<p onclick="steal()" onmouseover="x()">Text</p><script>alert(1)</script>'
            .'<a href="javascript:alert(1)">js</a><a href="JaVaScRiPt:alert(1)">js2</a><a href="data:text/html,<b>x</b>">data</a>'
            .'<img src="x.jpg" onerror="alert(1)"><iframe src="https://evil.example"></iframe><style>p{}</style>'
        );

        foreach (['onclick', 'onmouseover', 'onerror', '<script', 'alert(1)</script>', 'javascript:', 'JaVaScRiPt', 'data:', '<iframe', '<style'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $out);
        }
        $this->assertStringContainsString('<p>Text</p>', $out);
        $this->assertStringContainsString('<a>js</a>', $out); // link text kept, href removed
    }

    public function test_sanitising_twice_gives_the_same_result(): void
    {
        $once = $this->clean('<p style="text-align: center">a = b &amp; c + d</p><pre><code>if (a < b) {}</code></pre>');

        $this->assertSame($once, $this->clean($once));
    }

    public function test_text_align_with_a_trailing_newline_is_normalised_and_other_lines_are_not(): void
    {
        // Whitespace around the declaration is allowed (\s*); the output is rebuilt from the match.
        $this->assertSame('<p style="text-align: left">x</p>', $this->clean("<p style=\"text-align: left\n\">x</p>"));
        // A second line after the value is not part of an allowed declaration.
        $this->assertSame('<p>x</p>', $this->clean("<p style=\"text-align: left\nposition: fixed\">x</p>"));
    }
}
