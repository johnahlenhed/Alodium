// Small HTML helpers shared by the build checks (no parser dependency).

const VOID = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr']);

/** <h1>s outside and inside rich text (elements with the class "prose"), by tag depth. */
export function countH1(html) {
    const counts = { template: 0, content: 0 };
    const stack = []; // true for each open element that is (or is inside) .prose
    for (const [, closing, name, attrs] of html.matchAll(/<(\/?)([a-zA-Z][\w-]*)([^>]*)>/g)) {
        const tag = name.toLowerCase();
        if (closing) {
            stack.pop();
            continue;
        }
        const inProse = stack.at(-1) === true || /\sclass="[^"]*\bprose\b/.test(attrs);
        if (tag === 'h1') counts[inProse ? 'content' : 'template']++;
        if (!VOID.has(tag) && !attrs.endsWith('/')) stack.push(inProse);
    }
    return counts;
}

/** Text or an attribute value as written by Astro, back to the original string. */
export function decode(text) {
    return text
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&quot;|&#34;|&#x22;/g, '"')
        .replace(/&#39;|&#x27;|&apos;/g, "'")
        .replace(/&amp;/g, '&');
}
