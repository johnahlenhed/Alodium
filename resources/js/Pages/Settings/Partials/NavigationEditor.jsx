import InputError from '@/Components/InputError';
import { ArrowDown, ArrowUp, X } from 'lucide-react';
import { useLayoutEffect, useRef, useState } from 'react';

const FIELD = 'mt-1 block w-full rounded-md border-gray-400 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500';
const ICON_BUTTON =
    'rounded-md border border-gray-300 bg-surface p-1.5 text-gray-700 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-1 aria-disabled:cursor-not-allowed aria-disabled:opacity-40 aria-disabled:hover:bg-surface';
const ADD_BUTTON =
    'rounded-md border border-gray-300 bg-surface px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50';

/** Blog and Contact are ordinary entries; these add them with their usual label. */
const SUGGESTIONS = [
    { label: 'Blog', url: '/blog' },
    { label: 'Contact', url: '/contact' },
];

/**
 * The public site's main navigation: Home (fixed), then the entries in order.
 * An entry links to a published page (label optional: empty shows the page's
 * title) or to an address: a path on the site such as /blog, or another
 * website. Ordered with up and down buttons (no drag and drop). After a move,
 * an addition or a removal, focus goes to a control in the right place, and a
 * live region says what happened. Each entry is { key, page_id, url, label };
 * `key` only identifies the row here and is not sent.
 */
export default function NavigationEditor({ pages, items, onChange, max, frontPageId, errors }) {
    const [announcement, setAnnouncement] = useState('');
    const [pageToAdd, setPageToAdd] = useState('');
    const controls = useRef(new Map()); // "<key>:<label|url|up|down|remove>" or "add:<page|link>" -> element
    const focusNext = useRef(null);
    const keyCounter = useRef(0);

    const byId = new Map(pages.map((page) => [page.id, page]));
    const full = items.length >= max;
    const available = pages.filter((page) => !items.some((item) => item.page_id === page.id));
    const suggestions = SUGGESTIONS.filter((suggestion) => !items.some((item) => !item.page_id && item.url === suggestion.url));
    const nameOf = (item) => item.label?.trim() || byId.get(item.page_id)?.title || item.url?.trim() || 'New link';

    // Runs after React has updated the rows: put focus where the last action asked for it.
    useLayoutEffect(() => {
        if (focusNext.current) {
            controls.current.get(focusNext.current)?.focus();
            focusNext.current = null;
        }
    });

    const register = (key) => (element) => (element ? controls.current.set(key, element) : controls.current.delete(key));

    const update = (key, patch) => onChange(items.map((item) => (item.key === key ? { ...item, ...patch } : item)));

    const move = (item, offset) => {
        const from = items.indexOf(item);
        const to = from + offset;
        if (to < 0 || to >= items.length) return; // aria-disabled buttons stay focusable, so check here
        const next = [...items];
        [next[from], next[to]] = [next[to], next[from]];
        focusNext.current = `${item.key}:${offset < 0 ? 'up' : 'down'}`;
        onChange(next);
        setAnnouncement(`${nameOf(item)} moved to position ${to + 1} of ${next.length}.`);
    };

    const remove = (item) => {
        const index = items.indexOf(item);
        const next = items.filter((other) => other !== item);
        // The entry that takes its place, else the one before, else the add controls.
        const neighbour = next[index] ?? next[index - 1];
        focusNext.current = neighbour ? `${neighbour.key}:remove` : 'add:link';
        onChange(next);
        setAnnouncement(`${nameOf(item)} removed from the navigation.`);
    };

    const add = (entry) => {
        if (full) return;
        const item = { key: `new-${keyCounter.current++}`, page_id: null, url: '', label: '', ...entry };
        focusNext.current = `${item.key}:label`;
        onChange([...items, item]);
        setAnnouncement(`${nameOf(item)} added at the end of the navigation.`);
    };

    const addPage = () => {
        if (!pageToAdd) return;
        add({ page_id: Number(pageToAdd), url: null });
        setPageToAdd('');
    };

    // Errors that belong to the list, not to one field of an entry.
    const listErrors = [
        ...new Set(
            Object.entries(errors)
                .filter(([key]) => key === 'navigation' || /^navigation\.\d+$/.test(key))
                .map(([, message]) => message),
        ),
    ];

    const iconButton = (item, action, Icon, label, disabled, onClick) => (
        <button
            type="button"
            ref={register(`${item.key}:${action}`)}
            onClick={onClick}
            aria-disabled={disabled || undefined}
            aria-label={label}
            className={ICON_BUTTON}
        >
            <Icon className="h-4 w-4" aria-hidden="true" />
        </button>
    );

    return (
        <div className="space-y-5">
            <div>
                <h4 className="text-sm font-medium text-gray-700">In the navigation, in this order</h4>
                <ol className="mt-2 divide-y divide-gray-200 rounded-md border border-gray-200">
                    <li className="flex items-center justify-between gap-3 bg-gray-50 px-3 py-2.5 text-sm">
                        <span className="text-gray-900">Home</span>
                        <span className="text-xs text-gray-500">Always first</span>
                    </li>
                    {items.map((item, index) => {
                        const page = item.page_id ? byId.get(item.page_id) : null;
                        const id = `nav-${item.key}`;
                        const name = nameOf(item);
                        const error = (field) => errors[`navigation.${index}.${field}`];
                        return (
                            <li key={item.key} className="space-y-3 px-3 py-3">
                                <div className="flex items-start gap-3">
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium text-gray-900">{page ? page.title : 'Link'}</p>
                                        <p className="truncate text-xs text-gray-500">
                                            {page ? `Page · /${page.slug}` : item.url?.trim() || 'No address yet'}
                                        </p>
                                    </div>
                                    <span className="flex shrink-0 gap-1.5">
                                        {iconButton(item, 'up', ArrowUp, `Move ${name} up`, index === 0, () => move(item, -1))}
                                        {iconButton(item, 'down', ArrowDown, `Move ${name} down`, index === items.length - 1, () => move(item, 1))}
                                        {iconButton(item, 'remove', X, `Remove ${name} from the navigation`, false, () => remove(item))}
                                    </span>
                                </div>
                                <div className={page ? '' : 'grid grid-cols-1 gap-3 sm:grid-cols-2'}>
                                    <div>
                                        <label htmlFor={`${id}-label`} className="block text-xs font-medium text-gray-700">
                                            {page ? 'Label (optional)' : 'Label'}
                                        </label>
                                        <input
                                            id={`${id}-label`}
                                            ref={register(`${item.key}:label`)}
                                            type="text"
                                            className={FIELD}
                                            value={item.label ?? ''}
                                            maxLength={60}
                                            placeholder={page?.title}
                                            aria-describedby={page ? `${id}-label-help` : undefined}
                                            aria-invalid={error('label') ? true : undefined}
                                            onChange={(e) => update(item.key, { label: e.target.value })}
                                        />
                                        {page && (
                                            <p id={`${id}-label-help`} className="mt-1 text-xs text-gray-500">
                                                Leave empty to use the page title.
                                                {page.id === frontPageId && ' This is the front page, so Home already links to it and it isn’t shown.'}
                                            </p>
                                        )}
                                        <InputError message={error('label')} className="mt-1" />
                                    </div>
                                    {!page && (
                                        <div>
                                            <label htmlFor={`${id}-url`} className="block text-xs font-medium text-gray-700">
                                                Address
                                            </label>
                                            <input
                                                id={`${id}-url`}
                                                ref={register(`${item.key}:url`)}
                                                type="text"
                                                inputMode="url"
                                                className={FIELD}
                                                value={item.url ?? ''}
                                                maxLength={2048}
                                                placeholder="/blog or https://..."
                                                aria-describedby={`${id}-url-help`}
                                                aria-invalid={error('url') ? true : undefined}
                                                onChange={(e) => update(item.key, { url: e.target.value })}
                                            />
                                            <p id={`${id}-url-help`} className="mt-1 text-xs text-gray-500">
                                                A path on this site, starting with /, or a full address starting with https://.
                                            </p>
                                            <InputError message={error('url')} className="mt-1" />
                                        </div>
                                    )}
                                </div>
                                <InputError message={error('page_id')} />
                            </li>
                        );
                    })}
                </ol>
                {items.length === 0 && <p className="mt-2 text-sm text-gray-500">Only Home for now: add a page or a link below.</p>}
            </div>

            <div className="space-y-3 rounded-md border border-dashed border-gray-300 p-3">
                <h4 className="text-sm font-medium text-gray-700">Add to the navigation</h4>
                <div className="flex flex-wrap items-end gap-2">
                    <div className="min-w-0 flex-1">
                        <label htmlFor="nav-add-page" className="block text-xs font-medium text-gray-700">
                            A published page
                        </label>
                        <select
                            id="nav-add-page"
                            ref={register('add:page')}
                            className={FIELD}
                            value={pageToAdd}
                            disabled={full || available.length === 0}
                            onChange={(e) => setPageToAdd(e.target.value)}
                        >
                            <option value="">{available.length === 0 ? 'Every published page is in the navigation' : 'Choose a page...'}</option>
                            {available.map((page) => (
                                <option key={page.id} value={page.id}>
                                    {page.title}
                                </option>
                            ))}
                        </select>
                    </div>
                    <button type="button" className={ADD_BUTTON} onClick={addPage} disabled={full || !pageToAdd}>
                        Add page
                    </button>
                </div>
                <div className="flex flex-wrap gap-2">
                    <button type="button" ref={register('add:link')} className={ADD_BUTTON} onClick={() => add({})} disabled={full}>
                        Add a link
                    </button>
                    {suggestions.map((suggestion) => (
                        <button key={suggestion.url} type="button" className={ADD_BUTTON} onClick={() => add(suggestion)} disabled={full}>
                            Add {suggestion.label}
                        </button>
                    ))}
                </div>
                {full && <p className="text-sm text-gray-600">The navigation holds at most {max} links after Home. Remove one to add another.</p>}
            </div>

            {listErrors.map((message) => (
                <InputError key={message} message={message} />
            ))}

            <p className="sr-only" aria-live="polite">
                {announcement}
            </p>
        </div>
    );
}
