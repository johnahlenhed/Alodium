import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';

const formatDate = (value) => new Date(value).toLocaleDateString('sv-SE');

const ROLE_BADGE = {
    admin: 'bg-brand-100 text-brand-800',
    editor: 'bg-gray-100 text-gray-700',
};

function UserRow({ user, isSelf }) {
    const { data, setData, transform, patch, processing, errors } = useForm({ role: user.role });

    // Saves straight away when another role is picked. setData() only applies on the
    // next render, so transform() supplies the new value to this request.
    const changeRole = (role) => {
        setData('role', role);
        transform(() => ({ role }));
        patch(route('users.update', user.id), { preserveScroll: true });
    };

    return (
        <tr>
            <td className="px-6 py-4 font-medium text-gray-900">
                {user.name}
                {isSelf && <span className="ms-2 text-xs font-normal text-gray-500">(you)</span>}
            </td>
            <td className="px-6 py-4 text-gray-600">{user.email}</td>
            <td className="px-6 py-4">
                {isSelf ? (
                    // Changing your own role could leave the site without an admin.
                    <span className={'inline-flex rounded-full px-2 py-1 text-xs font-semibold ' + ROLE_BADGE[user.role]}>
                        {user.role}
                    </span>
                ) : (
                    <>
                        <select
                            aria-label={`Role for ${user.name}`}
                            value={data.role}
                            disabled={processing}
                            onChange={(e) => changeRole(e.target.value)}
                            className="rounded-md border-gray-400 py-1 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"
                        >
                            <option value="admin">Admin</option>
                            <option value="editor">Editor</option>
                        </select>
                        <InputError message={errors.role} className="mt-1" />
                    </>
                )}
            </td>
            <td className="px-6 py-4 text-gray-600">{formatDate(user.created_at)}</td>
            <td className="px-6 py-4 text-right">
                {!isSelf && (
                    <Link
                        href={route('users.destroy', user.id)}
                        method="delete"
                        as="button"
                        preserveScroll
                        onBefore={() =>
                            confirm(`Delete ${user.name}? Their posts, pages and media will be transferred to you.`)
                        }
                        className="text-danger-600 hover:text-danger-900"
                    >
                        Delete
                    </Link>
                )}
            </td>
        </tr>
    );
}

function CreateUserForm() {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        role: 'editor',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('users.store'), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <section className="bg-surface p-4 shadow sm:rounded-lg sm:p-6">
            <h3 className="text-lg font-medium text-gray-900">Create user</h3>
            <p className="mt-1 text-sm text-gray-600">
                The new user gets an email with a link to set their own password.
            </p>

            <form onSubmit={submit} className="mt-4 grid gap-4 sm:grid-cols-[1fr_1fr_auto_auto] sm:items-start">
                <div>
                    <InputLabel htmlFor="new-name" value="Name" />
                    <TextInput
                        id="new-name"
                        className="mt-1 block w-full"
                        value={data.name}
                        autoComplete="off"
                        onChange={(e) => setData('name', e.target.value)}
                        required
                    />
                    <InputError message={errors.name} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="new-email" value="Email" />
                    <TextInput
                        id="new-email"
                        type="email"
                        className="mt-1 block w-full"
                        value={data.email}
                        autoComplete="off"
                        onChange={(e) => setData('email', e.target.value)}
                        required
                    />
                    <InputError message={errors.email} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="new-role" value="Role" />
                    <select
                        id="new-role"
                        value={data.role}
                        onChange={(e) => setData('role', e.target.value)}
                        className="mt-1 block w-full rounded-md border-gray-400 shadow-sm focus:border-brand-500 focus:ring-brand-500"
                    >
                        <option value="editor">Editor</option>
                        <option value="admin">Admin</option>
                    </select>
                    <InputError message={errors.role} className="mt-2" />
                </div>

                <div className="sm:pt-6">
                    <PrimaryButton disabled={processing}>
                        {processing ? 'Creating…' : 'Create user'}
                    </PrimaryButton>
                </div>
            </form>
        </section>
    );
}

export default function Index({ users }) {
    const currentUserId = usePage().props.auth.user.id;

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Users
                </h2>
            }
        >
            <Head title="Users" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-4 sm:px-6 lg:px-8">
                    <p className="px-4 text-sm text-gray-600 sm:px-0">
                        <strong>Admins</strong> have full access. <strong>Editors</strong> can write and
                        publish, and edit published content by others, but cannot delete content or
                        use Analytics, Import or Users.
                    </p>

                    <CreateUserForm />

                    <div className="overflow-hidden bg-surface shadow sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <thead className="bg-gray-50">
                                <tr className="text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    <th className="px-6 py-3">Name</th>
                                    <th className="px-6 py-3">Email</th>
                                    <th className="px-6 py-3">Role</th>
                                    <th className="px-6 py-3">Joined</th>
                                    <th className="px-6 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200">
                                {users.map((user) => (
                                    <UserRow key={user.id} user={user} isSelf={user.id === currentUserId} />
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
