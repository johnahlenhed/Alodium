import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import { useRef, useState } from 'react';

/**
 * Picks the Open Graph (social sharing) image for a post or page.
 * The value is a full image URL: the 1200x630 "og" conversion of the chosen
 * media item. Media is loaded on demand when the picker opens, so the edit
 * page doesn't load the whole library up front. Uploads go to the same
 * endpoint as the Media page, which answers JSON to this kind of request.
 */
export default function OgImagePicker({ value, onChange, error }) {
    const [open, setOpen] = useState(false);
    const [media, setMedia] = useState(null);
    const [problem, setProblem] = useState(null);
    const [uploading, setUploading] = useState(false);
    const fileInput = useRef(null);

    const openPicker = async () => {
        setOpen(true);
        setProblem(null);
        try {
            const { data } = await window.axios.get(route('media.list'));
            setMedia(data);
        } catch {
            setProblem('Could not load the media library.');
        }
    };

    const choose = (item) => {
        // Media uploaded before the og conversion existed has no og_url yet.
        onChange(item.og_url ?? item.url);
        setOpen(false);
    };

    const upload = async (e) => {
        const file = e.target.files[0];
        if (!file) return;

        setUploading(true);
        setProblem(null);
        const body = new FormData();
        body.append('file', file);

        try {
            const { data } = await window.axios.post(route('media.store'), body);
            choose(data.media);
        } catch (err) {
            setProblem(err.response?.data?.errors?.file?.[0] ?? 'Upload failed.');
        } finally {
            setUploading(false);
            e.target.value = '';
        }
    };

    // Social platforms can't display SVG.
    const images = (media ?? []).filter((item) => item.mime_type !== 'image/svg+xml');

    return (
        <div>
            <InputLabel value="Social sharing image (Open Graph)" />
            <p className="mt-1 text-sm text-gray-500">
                Shown when the link is shared on social media. Cropped to 1200×630. If none
                is set, the featured image or the first image in the content is used.
            </p>

            {value && (
                <img
                    src={value}
                    alt="Selected social sharing image"
                    className="mt-3 aspect-[1200/630] w-full max-w-sm rounded-md border border-gray-200 bg-gray-100 object-cover"
                />
            )}

            <div className="mt-3 flex gap-3">
                <SecondaryButton type="button" onClick={openPicker}>
                    {value ? 'Change image' : 'Select from media library'}
                </SecondaryButton>
                {value && (
                    <button
                        type="button"
                        onClick={() => onChange('')}
                        className="text-sm text-red-600 hover:text-red-900"
                    >
                        Remove
                    </button>
                )}
            </div>
            <InputError message={error} className="mt-2" />

            <Modal show={open} onClose={() => setOpen(false)} maxWidth="2xl">
                <div className="p-6">
                    <div className="flex items-center justify-between gap-4">
                        <h2 className="text-lg font-medium text-gray-900">Choose an image</h2>
                        <input
                            ref={fileInput}
                            type="file"
                            accept=".jpg,.jpeg,.png,.gif,.webp"
                            className="hidden"
                            onChange={upload}
                        />
                        <SecondaryButton
                            type="button"
                            disabled={uploading}
                            onClick={() => fileInput.current.click()}
                        >
                            {uploading ? 'Uploading…' : 'Upload new'}
                        </SecondaryButton>
                    </div>

                    {problem && <p className="mt-4 text-sm text-red-600">{problem}</p>}

                    {media === null && !problem && (
                        <p className="mt-6 text-sm text-gray-500">Loading…</p>
                    )}

                    {media !== null && images.length === 0 && (
                        <p className="mt-6 text-sm text-gray-500">
                            No images in the media library yet. Upload one to get started.
                        </p>
                    )}

                    {images.length > 0 && (
                        <ul className="mt-6 grid max-h-[60vh] grid-cols-3 gap-3 overflow-y-auto sm:grid-cols-4">
                            {images.map((item) => (
                                <li key={item.id}>
                                    <button
                                        type="button"
                                        onClick={() => choose(item)}
                                        title={item.original_filename}
                                        className="block w-full overflow-hidden rounded-md border border-gray-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 hover:ring-2 hover:ring-indigo-300"
                                    >
                                        <img
                                            src={item.thumb_url}
                                            alt={item.original_filename}
                                            loading="lazy"
                                            className="aspect-square w-full bg-gray-100 object-cover"
                                        />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}

                    <div className="mt-6 flex justify-end">
                        <SecondaryButton type="button" onClick={() => setOpen(false)}>
                            Cancel
                        </SecondaryButton>
                    </div>
                </div>
            </Modal>
        </div>
    );
}
