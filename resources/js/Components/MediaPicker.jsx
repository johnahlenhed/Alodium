import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import { useRef, useState } from 'react';

/**
 * Picks an image from the media library, or uploads one and selects it.
 * Media is loaded on demand when the picker opens, so the edit page doesn't
 * load the whole library up front. Uploads go to the same endpoint as the Media
 * page, which answers JSON to this kind of request. SVGs are not offered.
 *
 * `toValue(item)` decides what is stored for the chosen media item (an
 * /api/media entry), e.g. a conversion URL. Used by OgImagePicker and page blocks.
 */
export default function MediaPicker({
    value,
    onChange,
    error,
    toValue,
    label,
    description,
    previewAlt = 'Selected image',
    previewClassName = 'mt-3 max-h-48 w-auto max-w-full rounded-md border border-gray-200 bg-gray-100 object-contain',
}) {
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
        onChange(toValue(item));
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

    // Not offered: social platforms can't display SVG.
    const images = (media ?? []).filter((item) => item.mime_type !== 'image/svg+xml');

    return (
        <div>
            <InputLabel value={label} />
            {description && <p className="mt-1 text-sm text-gray-500">{description}</p>}

            {value && <img src={value} alt={previewAlt} className={previewClassName} />}

            <div className="mt-3 flex gap-3">
                <SecondaryButton type="button" onClick={openPicker}>
                    {value ? 'Change image' : 'Select from media library'}
                </SecondaryButton>
                {value && (
                    <button
                        type="button"
                        onClick={() => onChange('')}
                        className="text-sm text-danger-600 hover:text-danger-900"
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

                    {problem && <p className="mt-4 text-sm text-danger-600">{problem}</p>}

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
                                        className="block w-full overflow-hidden rounded-md border border-gray-200 focus:outline-none focus:ring-2 focus:ring-brand-500 hover:ring-2 hover:ring-brand-300"
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
