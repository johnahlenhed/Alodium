export default function InputError({ message, className = '', ...props }) {
    return message ? (
        <p
            {...props}
            className={'text-sm text-danger-600 ' + className}
        >
            {message}
        </p>
    ) : null;
}
