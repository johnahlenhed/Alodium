import { Link } from '@inertiajs/react';

export default function ResponsiveNavLink({
    active = false,
    className = '',
    children,
    ...props
}) {
    return (
        <Link
            {...props}
            className={`flex w-full items-start border-l-4 py-2 pe-4 ps-3 ${
                active
                    ? 'border-nav-accent bg-nav-hover text-on-nav focus:border-on-nav'
                    : 'border-transparent text-on-nav-muted hover:border-on-nav-muted hover:bg-nav-hover hover:text-on-nav focus:border-on-nav-muted focus:bg-nav-hover focus:text-on-nav'
            } text-base font-medium transition duration-150 ease-in-out focus:outline-none ${className}`}
        >
            {children}
        </Link>
    );
}
