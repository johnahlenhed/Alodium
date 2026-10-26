import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { useLayoutEffect, useRef, useState } from 'react';

const CHECKBOX = 'rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500 disabled:opacity-50';

/**
 * The public site's main navigation: Home (fixed), the chosen pages in order,
 * then Blog and Contact. Pages are added with a checkbox and ordered with up
 * and down buttons (no drag and drop). After a move or a toggle, focus goes
 * back to the control that was used, and a live region says where it went.
 */
export default function NavigationEditor({ pages, selected, onChange, showBlog, onShowBlog, showContact, onShowContact, max, errors }) {
    const [announcement, setAnnouncement] = useState('');
    const controls = useRef(new Map()); // "<id>:<up|down|check>" -> element
    const focusNext = useRef(null);

    const byId = new Map(pages.map((page) => [page.id, page]));
    const inNav = selected.map((id) => byId.get(id)).filter(Boolean);
    const others = pages.filter((page) => !selected.includes(page.id));
    const full = selected.length >= max;

    // Runs after React has moved the rows: put focus back on the control that was used.
    useLayoutEffect(() => {
        if (focusNext.current) {
            controls.current.get(focusNext.current)?.focus();
            focusNext.current = null;
        }
    });

    const register = (key) => (element) => (element ? controls.current.set(key, element) : controls.current.delete(key));

    const move = (id, offset) => {
        const from = selected.indexOf(id);
        const to = from + offset;
        if (to < 0 || to >= selected.length) return; // aria-disabled buttons stay focusable, so check here
        const next = [...selected];
        [next[from], next[to]] = [next[to], next[from]];
        focusNext.current = `${id}:${offset < 0 ? 'up' : 'down'}`;
        onChange(next);
        setAnnouncement(`${byId.get(id).title} moved to position ${to + 1} of ${next.length}.`);
    };

    const toggle = (id, checked) => {
        if (checked && full) return;
        focusNext.current = `${id}:check`;
        onChange(checked ? [...selected, id] : selected.filter((other) => other !== id));
        setAnnouncement(checked ? `${byId.get(id).title} added at the end of the navigation.` : `${byId.get(id).title} removed from the navigation.`);
    };

    const navErrors = [
        ...new Set(
            Object.entries(errors)
                .filter(([key]) => key === 'navigation' || key.startsWith('navigation.'))
                .map(([, message]) => message),
        ),
    ];

    const moveButton = (page, index, direction) => {
        const disabled = direction === 'up' ? index === 0 : index === inNav.length - 1;
        const Icon = direction === 'up' ? ArrowUp : ArrowDown;
        return (
            <button
                type="button"
                ref={register(`${page.id}:${direction}`)}
                onClick={() => move(page.id, direction === 'up' ? -1 : 1)}
                aria-disabled={disabled}
                aria-label={`Move ${page.title} ${direction}`}
                className="rounded-md border border-gray-300 bg-white p-1.5 text-gray-700 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-1 aria-disabled:cursor-not-allowed aria-disabled:opacity-40 aria-disabled:hover:bg-white"
            >
                <Icon className="h-4 w-4" aria-hidden="true" />
            </button>
        );
    };

    const pageCheckbox = (page, checked) => (
        <label className="flex min-w-0 flex-1 items-center gap-3">
            {/* A plain input (styled like Checkbox), because focus is restored through its ref. */}
            <input
                type="checkbox"
                ref={register(`${page.id}:check`)}
                className={CHECKBOX}
                checked={checked}
                disabled={!checked && full}
                onChange={(e) => toggle(page.id, e.target.checked)}
            />
            <span className="truncate text-sm text-gray-900">{page.title}</span>
            <span className="truncate text-xs text-gray-500">/{page.slug}</span>
        </label>
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
                    {inNav.map((page, index) => (
                        <li key={page.id} className="flex items-center gap-3 px-3 py-2">
                            {pageCheckbox(page, true)}
                            <span className="flex shrink-0 gap-1.5">
                                {moveButton(page, index, 'up')}
                                {moveButton(page, index, 'down')}
                            </span>
                        </li>
                    ))}
                    <li className="px-3 py-2.5">
                        <label className="flex items-center gap-3 text-sm text-gray-900">
                            <Checkbox checked={showBlog} onChange={(e) => onShowBlog(e.target.checked)} />
                            Blog
                        </label>
                    </li>
                    <li className="px-3 py-2.5">
                        <label className="flex items-center gap-3 text-sm text-gray-900">
                            <Checkbox checked={showContact} onChange={(e) => onShowContact(e.target.checked)} />
                            Contact
                        </label>
                    </li>
                </ol>
                {inNav.length === 0 && <p className="mt-2 text-sm text-gray-500">No pages yet: tick a page below to add it.</p>}
            </div>

            <div>
                <h4 className="text-sm font-medium text-gray-700">Other published pages</h4>
                {others.length === 0 ? (
                    <p className="mt-2 text-sm text-gray-500">
                        {pages.length === 0 ? 'There are no published pages yet.' : 'All published pages are in the navigation.'}
                    </p>
                ) : (
                    <ul className="mt-2 divide-y divide-gray-200 rounded-md border border-gray-200">
                        {others.map((page) => (
                            <li key={page.id} className="flex items-center px-3 py-2">
                                {pageCheckbox(page, false)}
                            </li>
                        ))}
                    </ul>
                )}
                {full && <p className="mt-2 text-sm text-gray-600">The navigation holds at most {max} pages. Remove one to add another.</p>}
            </div>

            {navErrors.map((message) => (
                <InputError key={message} message={message} />
            ))}

            <p className="sr-only" aria-live="polite">
                {announcement}
            </p>
        </div>
    );
}
