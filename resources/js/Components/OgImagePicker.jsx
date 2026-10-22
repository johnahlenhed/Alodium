import MediaPicker from '@/Components/MediaPicker';

/**
 * Picks the Open Graph (social sharing) image for a post or page.
 * The value is a full image URL: the 1200x630 "og" conversion of the chosen
 * media item.
 */
export default function OgImagePicker({ value, onChange, error }) {
    return (
        <MediaPicker
            value={value}
            onChange={onChange}
            error={error}
            // Media uploaded before the og conversion existed has no og_url yet.
            toValue={(item) => item.og_url ?? item.url}
            label="Social sharing image (Open Graph)"
            description="Shown when the link is shared on social media. Cropped to 1200×630. If none is set, the featured image or the first image in the content is used."
            previewAlt="Selected social sharing image"
            previewClassName="mt-3 aspect-[1200/630] w-full max-w-sm rounded-md border border-gray-200 bg-gray-100 object-cover"
        />
    );
}
