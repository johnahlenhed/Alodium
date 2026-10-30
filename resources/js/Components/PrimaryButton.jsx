export default function PrimaryButton({
    className = '',
    disabled,
    children,
    ...props
}) {
    return (
        <button
            {...props}
            className={
                `inline-flex items-center rounded-md border border-transparent bg-primary px-4 py-2 text-xs font-semibold uppercase tracking-widest text-on-primary transition duration-150 ease-in-out hover:bg-primary-hover focus:bg-primary-hover focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 active:bg-primary-active ${
                    disabled && 'opacity-25'
                } ` + className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
