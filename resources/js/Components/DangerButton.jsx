export default function DangerButton({
    className = '',
    disabled,
    children,
    ...props
}) {
    return (
        <button
            {...props}
            className={
                `inline-flex items-center rounded-md border border-transparent bg-danger-fill px-4 py-2 text-xs font-semibold uppercase tracking-widest text-on-danger transition duration-150 ease-in-out hover:bg-danger-fill-hover focus:outline-none focus:ring-2 focus:ring-danger-500 focus:ring-offset-2 active:bg-danger-fill-active ${
                    disabled && 'opacity-25'
                } ` + className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
