// Small CSS helpers shared by the checks (no parser dependency). They handle
// the plain CSS this project writes, not every CSS syntax.

/** Rules as { selectors, declarations, at } in source order; `text` without comments. */
export function parseRules(text, at = '') {
    const rules = [];
    let i = 0;
    while (i < text.length) {
        const open = text.indexOf('{', i);
        if (open === -1) break;
        const prelude = text.slice(i, open).trim();
        let depth = 1;
        let j = open + 1;
        while (depth > 0 && j < text.length) {
            if (text[j] === '{') depth++;
            else if (text[j] === '}') depth--;
            j++;
        }
        const body = text.slice(open + 1, j - 1);
        if (prelude.startsWith('@font-face')) {
            // not a style rule
        } else if (prelude.startsWith('@')) {
            rules.push(...parseRules(body, prelude));
        } else {
            const declarations = {};
            for (const part of body.split(/;(?![^(]*\))/)) {
                const colon = part.indexOf(':');
                if (colon > 0) declarations[part.slice(0, colon).trim()] = part.slice(colon + 1).trim();
            }
            rules.push({ selectors: splitTopLevel(prelude), declarations, at });
        }
        i = j;
    }
    return rules;
}

/** Splits on commas outside parentheses. */
export function splitTopLevel(text, separator = ',') {
    const parts = [];
    let depth = 0;
    let current = '';
    for (const char of text) {
        if (char === '(') depth++;
        if (char === ')') depth--;
        if (char === separator && depth === 0) {
            parts.push(current.trim());
            current = '';
        } else {
            current += char;
        }
    }
    parts.push(current.trim());
    return parts;
}

export const stripComments = (css) => css.replace(/\/\*[\s\S]*?\*\//g, '');

const px = (value, unit) => Number(value) * (unit === 'rem' || unit === 'em' ? 16 : 1);

/**
 * Whether a rule's at-rule applies at a viewport `width` (px). Knows width
 * queries joined with "and", in both the min-width and the range syntax a
 * minifier may write (width>=720px). Any other at-rule never applies.
 */
export function matchesWidth(at, width) {
    if (!at) return true;
    const query = at.match(/^@media\s+(.+)$/)?.[1];
    if (!query) return false;
    return query.split(/\s+and\s+/).every((part) => {
        let m;
        if ((m = part.match(/^\((min|max)-width:\s*([\d.]+)(px|rem|em)\)$/))) {
            return m[1] === 'min' ? width >= px(m[2], m[3]) : width <= px(m[2], m[3]);
        }
        if ((m = part.match(/^\(width\s*(>=|<=|>|<)\s*([\d.]+)(px|rem|em)\)$/))) {
            const limit = px(m[2], m[3]);
            return { '>=': width >= limit, '<=': width <= limit, '>': width > limit, '<': width < limit }[m[1]];
        }
        return false;
    });
}

/**
 * Classes a compound selector of only classes (".a.b:not(.c)") needs and
 * excludes, or null for any other selector (tags, combinators, ...).
 */
function classSelector(selector) {
    let rest = selector;
    const needs = [];
    const excludes = [];
    while (rest) {
        let m;
        if ((m = rest.match(/^\.([\w-]+)/))) needs.push(m[1]);
        else if ((m = rest.match(/^:not\(\.([\w-]+)\)/))) excludes.push(m[1]);
        else return null;
        rest = rest.slice(m[0].length);
    }
    return { needs, excludes, specificity: needs.length + excludes.length };
}

/**
 * The value of `property` for an element with `classes` at a viewport `width`:
 * of the class-only rules that match, the one with the highest specificity,
 * then the last. Undefined when none sets it.
 */
export function computedValue(rules, classes, width, property) {
    let best = null;
    rules.forEach((rule, order) => {
        if (!(property in rule.declarations) || !matchesWidth(rule.at, width)) return;
        for (const selector of rule.selectors) {
            const parsed = classSelector(selector);
            if (!parsed || !parsed.needs.every((c) => classes.includes(c)) || parsed.excludes.some((c) => classes.includes(c))) continue;
            if (!best || parsed.specificity > best.specificity || (parsed.specificity === best.specificity && order >= best.order)) {
                best = { specificity: parsed.specificity, order, value: rule.declarations[property] };
            }
        }
    });
    return best?.value;
}
