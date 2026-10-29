import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import NavLink from '@/Components/NavLink';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink';
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function AuthenticatedLayout({ header, children }) {
    const { auth, flash, unreadSubmissions = 0 } = usePage().props;
    const user = auth.user;
    // Analytics, Import, Users and Settings are admin-only (the routes enforce it; this only hides the links).
    const isAdmin = user.role === 'admin';

    const [showingNavigationDropdown, setShowingNavigationDropdown] =
        useState(false);

    return (
        <div className="min-h-screen bg-canvas">
            <nav className="border-b border-gray-100 bg-surface">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex h-16 justify-between">
                        <div className="flex">
                            <div className="flex shrink-0 items-center">
                                <Link href="/">
                                    <ApplicationLogo className="block h-9 w-auto fill-current text-primary" />
                                </Link>
                            </div>

                            <div className="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                                <NavLink
                                    href={route('dashboard')}
                                    active={route().current('dashboard')}
                                >
                                    Dashboard
                                </NavLink>
                                <NavLink
                                    href={route('posts.index')}
                                    active={route().current('posts.*')}
                                >
                                    Posts
                                </NavLink>
                                <NavLink
                                    href={route('pages.index')}
                                    active={route().current('pages.*')}
                                >
                                    Pages
                                </NavLink>
                                <NavLink
                                    href={route('media.index')}
                                    active={route().current('media.*')}
                                >
                                    Media
                                </NavLink>
                                <NavLink
                                    href={route('submissions.index')}
                                    active={route().current('submissions.*')}
                                >
                                    Submissions
                                    {unreadSubmissions > 0 && (
                                        <span className="ms-1.5 rounded-full bg-primary px-1.5 py-0.5 text-xs font-semibold leading-none text-on-primary">
                                            {unreadSubmissions}
                                            <span className="sr-only"> unread</span>
                                        </span>
                                    )}
                                </NavLink>
                                {isAdmin && (
                                    <>
                                        <NavLink
                                            href={route('analytics.index')}
                                            active={route().current('analytics.*')}
                                        >
                                            Analytics
                                        </NavLink>
                                        {/* Utilities are kept apart from content sections. */}
                                        <span className="h-6 w-px self-center bg-gray-200" aria-hidden="true" />
                                        <NavLink
                                            href={route('import.create')}
                                            active={route().current('import.*')}
                                        >
                                            Import
                                        </NavLink>
                                        <NavLink
                                            href={route('users.index')}
                                            active={route().current('users.*')}
                                        >
                                            Users
                                        </NavLink>
                                        <NavLink
                                            href={route('settings.edit')}
                                            active={route().current('settings.*')}
                                        >
                                            Settings
                                        </NavLink>
                                    </>
                                )}
                            </div>
                        </div>

                        <div className="hidden sm:ms-6 sm:flex sm:items-center">
                            <div className="relative ms-3">
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <span className="inline-flex rounded-md">
                                            <button
                                                type="button"
                                                className="inline-flex items-center rounded-md border border-transparent bg-surface px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none"
                                            >
                                                {user.name}

                                                <svg
                                                    className="-me-0.5 ms-2 h-4 w-4"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                >
                                                    <path
                                                        fillRule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clipRule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </span>
                                    </Dropdown.Trigger>

                                    <Dropdown.Content>
                                        <Dropdown.Link
                                            href={route('profile.edit')}
                                        >
                                            Profile
                                        </Dropdown.Link>
                                        <Dropdown.Link
                                            href={route('logout')}
                                            method="post"
                                            as="button"
                                        >
                                            Log Out
                                        </Dropdown.Link>
                                    </Dropdown.Content>
                                </Dropdown>
                            </div>
                        </div>

                        <div className="-me-2 flex items-center sm:hidden">
                            <button
                                onClick={() =>
                                    setShowingNavigationDropdown(
                                        (previousState) => !previousState,
                                    )
                                }
                                className="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none"
                            >
                                <svg
                                    className="h-6 w-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        className={
                                            !showingNavigationDropdown
                                                ? 'inline-flex'
                                                : 'hidden'
                                        }
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        className={
                                            showingNavigationDropdown
                                                ? 'inline-flex'
                                                : 'hidden'
                                        }
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div
                    className={
                        (showingNavigationDropdown ? 'block' : 'hidden') +
                        ' sm:hidden'
                    }
                >
                    <div className="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink
                            href={route('dashboard')}
                            active={route().current('dashboard')}
                        >
                            Dashboard
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            href={route('posts.index')}
                            active={route().current('posts.*')}
                        >
                            Posts
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            href={route('pages.index')}
                            active={route().current('pages.*')}
                        >
                            Pages
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            href={route('media.index')}
                            active={route().current('media.*')}
                        >
                            Media
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            href={route('submissions.index')}
                            active={route().current('submissions.*')}
                        >
                            Submissions
                            {unreadSubmissions > 0 && ` (${unreadSubmissions} unread)`}
                        </ResponsiveNavLink>
                        {isAdmin && (
                            <>
                                <ResponsiveNavLink
                                    href={route('analytics.index')}
                                    active={route().current('analytics.*')}
                                >
                                    Analytics
                                </ResponsiveNavLink>
                                <div className="mt-2 border-t border-gray-200 px-4 pb-1 pt-3 text-xs font-semibold uppercase tracking-wider text-gray-500">
                                    Tools
                                </div>
                                <ResponsiveNavLink
                                    href={route('import.create')}
                                    active={route().current('import.*')}
                                >
                                    Import
                                </ResponsiveNavLink>
                                <ResponsiveNavLink
                                    href={route('users.index')}
                                    active={route().current('users.*')}
                                >
                                    Users
                                </ResponsiveNavLink>
                                <ResponsiveNavLink
                                    href={route('settings.edit')}
                                    active={route().current('settings.*')}
                                >
                                    Settings
                                </ResponsiveNavLink>
                            </>
                        )}
                    </div>

                    <div className="border-t border-gray-200 pb-1 pt-4">
                        <div className="px-4">
                            <div className="text-base font-medium text-gray-800">
                                {user.name}
                            </div>
                            <div className="text-sm font-medium text-gray-500">
                                {user.email}
                            </div>
                        </div>

                        <div className="mt-3 space-y-1">
                            <ResponsiveNavLink href={route('profile.edit')}>
                                Profile
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                method="post"
                                href={route('logout')}
                                as="button"
                            >
                                Log Out
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            {header && (
                <header className="bg-surface shadow">
                    <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                        {header}
                    </div>
                </header>
            )}

            {(flash?.success || flash?.warning) && (
                <div className="mx-auto max-w-7xl space-y-3 px-4 pt-6 sm:px-6 lg:px-8">
                    {flash.success && (
                        <div role="status" className="rounded-md border border-success-200 bg-success-50 p-4 text-sm text-success-800">
                            {flash.success}
                        </div>
                    )}
                    {flash.warning && (
                        <div role="alert" className="rounded-md border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800">
                            {flash.warning}
                        </div>
                    )}
                </div>
            )}

            <main>{children}</main>
        </div>
    );
}
