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

/**
 * A compound selector of an optional tag and classes ("ul", ".a.b",
 * "a.b:not(.c)"), or null for anything else (attributes, pseudo-classes, ...).
 */
function compoundSelector(selector) {
    const tag = selector.match(/^[a-z][\w-]*/i)?.[0] ?? null;
    const classes = tag ? classSelector(selector.slice(tag.length)) ?? (selector === tag ? { needs: [], excludes: [], specificity: 0 } : null) : classSelector(selector);
    return classes && { tag, ...classes };
}

const matchesCompound = (compound, element) =>
    (compound.tag === null || compound.tag === element.tag) && compound.needs.every((c) => element.classes.includes(c)) && !compound.excludes.some((c) => element.classes.includes(c));

/** Whether winningDeclaration() can evaluate a selector (compounds joined by spaces); it ignores rules it can't. */
export const isModelledSelector = (selector) => selector.trim().split(/\s+/).every((compound) => compoundSelector(compound) !== null);

/**
 * The declaration that applies to an element inside others: `chain` lists the
 * element's ancestors and then the element, each as { tag, classes }, and
 * `properties` the property and any shorthand that also sets it (for example
 * ['margin-inline-start', 'margin-inline']). Knows selectors of compounds (see
 * compoundSelector) joined by spaces (the descendant combinator); rules with
 * any other selector are ignored. Of the rules that match, the one with the
 * most classes wins, then the most tags, then the last. { property, value },
 * or undefined when none sets it.
 */
export function winningDeclaration(rules, chain, width, properties) {
    const element = chain.at(-1);
    let best = null;
    rules.forEach((rule, order) => {
        const property = properties.find((name) => name in rule.declarations);
        if (!property || !matchesWidth(rule.at, width)) return;
        for (const selector of rule.selectors) {
            const compounds = selector.trim().split(/\s+/).map(compoundSelector);
            if (compounds.some((c) => c === null) || !matchesCompound(compounds.at(-1), element)) continue;
            // Each remaining compound, right to left, must match an ancestor further up.
            let ancestor = chain.length - 2;
            const matched = compounds.slice(0, -1).reverse().every((compound) => {
                while (ancestor >= 0 && !matchesCompound(compound, chain[ancestor])) ancestor--;
                return ancestor-- >= 0;
            });
            if (!matched) continue;
            const specificity = compounds.reduce((sum, c) => sum + c.specificity * 1000 + (c.tag ? 1 : 0), 0);
            if (!best || specificity > best.specificity || (specificity === best.specificity && order >= best.order)) {
                best = { specificity, order, property, value: rule.declarations[property] };
            }
        }
    });
    return best ? { property: best.property, value: best.value } : undefined;
}

/** computedValue() for an element inside others (see winningDeclaration). */
export const computedValueIn = (rules, chain, width, property) => winningDeclaration(rules, chain, width, [property])?.value;

/**
 * A CSS length in px at a viewport `width`: numbers in px, rem (16px) or vw, and
 * unitless ones; + - * /; calc(), min(), max() and clamp(); and var(--name),
 * looked up in `vars` (each value itself an expression) or taken from the var's
 * fallback. Anything else throws, so a declaration this can't read fails a
 * check instead of passing unread. Only for this project's own CSS: the text is
 * reduced to numbers, operators and the three functions before it is evaluated.
 */
export function cssLength(expression, width, vars = {}, depth = 0) {
    if (depth > 8) throw new Error(`cssLength: variables nested too deep in "${expression}"`);
    let text = String(expression);
    // Innermost var() first, so a fallback holding another var() works.
    for (let guard = 0; /var\(/.test(text); guard++) {
        if (guard > 20) throw new Error(`cssLength: can't resolve the variables in "${expression}"`);
        text = text.replace(/var\(\s*(--[\w-]+)\s*(?:,\s*([^()]*))?\)/, (m, name, fallback) => {
            const value = vars[name] ?? fallback;
            if (value === undefined) throw new Error(`cssLength: ${name} has no value in "${expression}"`);
            return `(${cssLength(value, width, vars, depth + 1)})`;
        });
    }
    const UNIT = { px: 1, rem: 16, vw: width / 100 };
    text = text
        .replace(/\bcalc\(/g, '(')
        .replace(/(\d*\.?\d+)(px|rem|vw)\b/g, (m, n, unit) => String(Number(n) * UNIT[unit]));
    const rest = text.replace(/\b(min|max|clamp)\(/g, '(');
    if (!/^[\d.\s+\-*/(),e]*$/.test(rest) || /[a-df-z]/i.test(rest)) throw new Error(`cssLength: can't read "${expression}" (left "${rest}")`);
    const clamp = (low, value, high) => Math.max(low, Math.min(value, high));
    const result = new Function('min', 'max', 'clamp', `"use strict"; return (${text});`)(Math.min, Math.max, clamp);
    if (!Number.isFinite(result)) throw new Error(`cssLength: "${expression}" is not a length`);
    return result;
}
