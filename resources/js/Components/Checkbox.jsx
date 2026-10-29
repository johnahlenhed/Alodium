export default function Checkbox({ className = '', ...props }) {
    return (
        <input
            {...props}
            type="checkbox"
            className={
                'rounded border-gray-400 text-brand-600 shadow-sm focus:ring-brand-500 ' +
                className
            }
        />
    );
}
