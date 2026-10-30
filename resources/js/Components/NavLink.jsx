import { Link } from '@inertiajs/react';

export default function NavLink({
    active = false,
    className = '',
    children,
    ...props
}) {
    return (
        <Link
            {...props}
            className={
                'inline-flex items-center border-b-2 px-1 pt-1 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none ' +
                (active
                    ? 'border-nav-accent text-on-nav focus:border-on-nav'
                    : 'border-transparent text-on-nav-muted hover:border-on-nav-muted hover:text-on-nav focus:border-on-nav-muted focus:text-on-nav') +
                className
            }
        >
            {children}
        </Link>
    );
}
