import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import BrandColorField from './Partials/BrandColorField';
import NavBackgroundField from './Partials/NavBackgroundField';
import NavigationEditor from './Partials/NavigationEditor';
import ThemePreview from './Partials/ThemePreview';

const fieldClass = 'mt-1 block w-full rounded-md border-gray-400 shadow-sm focus:border-brand-500 focus:ring-brand-500';

function Section({ title, description, children }) {
    const id = `section-${title.toLowerCase().replace(/\s+/g, '-')}`;
    return (
        <section aria-labelledby={id} className="bg-surface p-4 shadow sm:rounded-lg sm:p-8">
            <h3 id={id} className="text-lg font-medium text-gray-900">
                {title}
            </h3>
            {description && <p className="mt-1 text-sm text-gray-600">{description}</p>}
            <div className="mt-6 space-y-6">{children}</div>
        </section>
    );
}

function CharacterCount({ value, max, id }) {
    return (
        <p id={id} className="mt-1 text-xs text-gray-500">
            {value.length} / {max} characters
        </p>
    );
}

/** Radio buttons for one setting (corners, buttons). */
function ChoiceGroup({ legend, name, options, value, onChange, error }) {
    return (
        <fieldset>
            <legend className="text-sm font-medium text-gray-700">{legend}</legend>
            <div className="mt-2 flex flex-wrap gap-x-6 gap-y-2">
                {options.map((option) => (
                    <label key={option.value} className="flex items-center gap-2 text-sm text-gray-900">
                        <input
                            type="radio"
                            name={name}
                            value={option.value}
                            checked={value === option.value}
                            onChange={() => onChange(option.value)}
                            className="border-gray-400 text-brand-600 focus:ring-brand-500"
                        />
                        {option.label}
                    </label>
                ))}
            </div>
            <InputError message={error} className="mt-2" />
        </fieldset>
    );
}

export default function Edit({ settings, pendingFrontPage, logo, pages, options }) {
    const form = useForm({
        site_name: settings.site_name,
        site_description: settings.site_description ?? '',
        footer_text: settings.footer_text ?? '',
        show_site_name: settings.show_site_name,
        logo_height: settings.logo_height,
        brand_color: settings.brand_color,
        nav_background_color: settings.nav_background_color ?? null,
        font_body: settings.font_body,
        font_heading: settings.font_heading,
        corners: settings.corners,
        buttons: settings.buttons,
        // `key` identifies a row in NavigationEditor; it is left out when saving.
        navigation: settings.navigation.map((item, index) => ({
            key: `saved-${index}`,
            page_id: item.page_id,
            url: item.url ?? '',
            label: item.label ?? '',
        })),
        front_page_id: settings.front_page_id ?? '',
        logo: null,
        remove_logo: false,
    });
    const { data, setData, errors, processing, progress } = form;

    // A local preview of a newly chosen logo file (revoked when replaced).
    const fileInput = useRef(null);
    const [newLogoUrl, setNewLogoUrl] = useState(null);
    useEffect(() => () => newLogoUrl && URL.revokeObjectURL(newLogoUrl), [newLogoUrl]);

    const chooseLogo = (file) => {
        setData((current) => ({ ...current, logo: file ?? null, remove_logo: false }));
        setNewLogoUrl(file ? URL.createObjectURL(file) : null);
    };

    const submit = (e) => {
        e.preventDefault();
        // A file upload needs multipart, and browsers only send multipart with POST, so the
        // form is POSTed with _method=PUT (Laravel's method spoofing). Inertia sends
        // FormData when a file is attached and JSON otherwise; both carry _method.
        form.transform((current) => ({
            ...current,
            _method: 'put',
            front_page_id: current.front_page_id === '' ? null : Number(current.front_page_id),
            navigation: current.navigation.map(({ page_id, url, label }) => ({ page_id, url: page_id ? null : url, label })),
        }));
        form.post(route('settings.update'), {
            preserveScroll: true,
            // With a logo, the form goes as multipart, where Inertia writes lists as "navigation[][label]"
            // by default: PHP then reads every field as an entry of its own. Indices keep each entry together.
            queryStringArrayFormat: 'indices',
            onSuccess: () => {
                setData((current) => ({ ...current, logo: null, remove_logo: false }));
                setNewLogoUrl(null);
                if (fileInput.current) fileInput.current.value = '';
            },
        });
    };

    const fontLabel = (value) => options.fonts.find((font) => font.value === value)?.label ?? value;
    const radius = options.corners.find((corner) => corner.value === data.corners)?.radius ?? '6px';
    // The chosen front page; pendingFrontPage is one that isn't published (it stays chosen).
    const frontPage = [...pages, ...(pendingFrontPage ? [pendingFrontPage] : [])].find((page) => String(page.id) === String(data.front_page_id));
    const frontPageIsPending = Boolean(pendingFrontPage) && frontPage?.id === pendingFrontPage.id;
    const shownLogo = newLogoUrl ?? (data.remove_logo ? null : logo?.url);
    // The preview uses the header's rules (.site-brand__logo in astro/src/styles/site.css): the
    // chosen height in rem, capped on narrow screens (at most 48px up to a 512px-wide window, the
    // full 96px from 1024px), so it shows the size for this window's width; the width follows the
    // logo's proportions, at most 64/9 times the height.
    const logoHeightCss = `min(${data.logo_height / 16}rem, clamp(3rem, 9.375vw, 6rem))`;
    // The narrowest window that shows the chosen height in full (9.375vw = height).
    const fullSizeFrom = Math.ceil(data.logo_height / 0.09375);

    return (
        <AuthenticatedLayout header={<h2 className="text-page-title font-semibold text-gray-800">Site settings</h2>}>
            <Head title="Site settings" />

            <form onSubmit={submit} className="mx-auto max-w-3xl space-y-6 py-12" noValidate>
                <p className="px-4 text-sm text-gray-600 sm:px-0">
                    These settings control the public site&rsquo;s name, look and navigation. After saving, the site is
                    rebuilt in the background.
                </p>

                <Section title="Identity">
                    <div>
                        <InputLabel htmlFor="site_name" value="Site name" />
                        <TextInput
                            id="site_name"
                            className="mt-1 block w-full"
                            value={data.site_name}
                            maxLength={80}
                            required
                            aria-describedby="site_name_count"
                            onChange={(e) => setData('site_name', e.target.value)}
                        />
                        <CharacterCount id="site_name_count" value={data.site_name} max={80} />
                        <InputError message={errors.site_name} className="mt-2" />
                    </div>

                    <div>
                        <InputLabel htmlFor="site_description" value="Description (optional)" />
                        <textarea
                            id="site_description"
                            rows={3}
                            maxLength={300}
                            className={fieldClass}
                            value={data.site_description}
                            aria-describedby="site_description_help site_description_count"
                            // One paragraph: line breaks become spaces.
                            onChange={(e) => setData('site_description', e.target.value.replace(/\s*[\r\n]+\s*/g, ' '))}
                        />
                        <p id="site_description_help" className="mt-1 text-sm text-gray-500">
                            A sentence about the site, used by search engines and when links are shared.
                        </p>
                        <CharacterCount id="site_description_count" value={data.site_description} max={300} />
                        <InputError message={errors.site_description} className="mt-2" />
                    </div>

                    <div>
                        <InputLabel htmlFor="logo" value="Logo (optional)" />
                        <p id="logo_help" className="mt-1 text-sm text-gray-500">
                            PNG, JPEG, WebP or SVG, at most 2 MB. PNG, JPEG and WebP: 64 to 4000 pixels per side; a
                            transparent PNG works well. SVG: it needs a viewBox or a width and height, and scripts and
                            links to other sites are removed when it is saved. Its height on the site is set below.
                        </p>
                        {shownLogo && (
                            // As in the site header: the logo at the chosen height, with the name when it is shown.
                            <div className="mt-3 flex max-w-full items-center gap-3 overflow-hidden rounded-md border border-gray-200 bg-[repeating-conic-gradient(rgb(var(--color-gray-100))_0%_25%,rgb(var(--color-surface))_0%_50%)] bg-[length:16px_16px] p-3">
                                <img
                                    src={shownLogo}
                                    alt={newLogoUrl ? 'New logo, not saved yet' : 'Current logo'}
                                    className="w-auto flex-none object-contain object-left"
                                    style={{ height: logoHeightCss, maxWidth: `min(100%, ${logoHeightCss} * 64 / 9)` }}
                                />
                                {data.show_site_name && <span className="truncate text-[1.1875rem] font-semibold text-gray-900">{data.site_name}</span>}
                            </div>
                        )}
                        <input
                            id="logo"
                            ref={fileInput}
                            type="file"
                            accept="image/png,image/jpeg,image/webp,image/svg+xml"
                            aria-describedby="logo_help"
                            onChange={(e) => chooseLogo(e.target.files[0])}
                            className="mt-3 block w-full text-sm text-gray-700 file:me-4 file:rounded-md file:border-0 file:bg-gray-100 file:px-4 file:py-2 file:text-sm file:font-medium hover:file:bg-gray-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                        />
                        {progress && <p className="mt-2 text-sm text-gray-600">Uploading… {progress.percentage}%</p>}
                        {logo && !data.logo && (
                            <label className="mt-3 flex items-center gap-2 text-sm text-gray-900">
                                <Checkbox checked={data.remove_logo} onChange={(e) => setData('remove_logo', e.target.checked)} />
                                Remove the logo
                            </label>
                        )}
                        <InputError message={errors.logo} className="mt-2" />
                    </div>

                    {shownLogo && (
                        <div>
                            <InputLabel htmlFor="logo_height" value="Logo height on the site" />
                            <div className="mt-2 flex items-center gap-4">
                                <input
                                    id="logo_height"
                                    type="range"
                                    min={options.logoHeight.min}
                                    max={options.logoHeight.max}
                                    step={1}
                                    value={data.logo_height}
                                    aria-describedby="logo_height_help"
                                    aria-valuetext={`${data.logo_height} pixels`}
                                    onChange={(e) => setData('logo_height', Number(e.target.value))}
                                    className="w-full max-w-xs accent-brand-600"
                                />
                                <output htmlFor="logo_height" className="w-14 flex-none text-sm tabular-nums text-gray-900">
                                    {data.logo_height} px
                                </output>
                                {data.logo_height !== options.logoHeight.default && (
                                    <button
                                        type="button"
                                        onClick={() => setData('logo_height', options.logoHeight.default)}
                                        className="flex-none text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                                    >
                                        Reset to {options.logoHeight.default} px
                                    </button>
                                )}
                            </div>
                            <p id="logo_height_help" className="mt-1 text-sm text-gray-500">
                                {options.logoHeight.min} to {options.logoHeight.max} pixels high. The width follows the
                                logo&rsquo;s proportions.
                                {data.logo_height > 48
                                    ? ` Shown ${data.logo_height} px high on screens at least ${fullSizeFrom} px wide; narrower screens show it smaller, down to 48 px on a phone. The preview above follows the width of this window.`
                                    : ' Shown at this size on every screen; the preview above shows it.'}
                            </p>
                            <InputError message={errors.logo_height} className="mt-2" />
                        </div>
                    )}

                    <div>
                        <label className="flex items-center gap-2 text-sm text-gray-900">
                            <Checkbox
                                checked={data.show_site_name}
                                aria-describedby="show_site_name_help"
                                onChange={(e) => setData('show_site_name', e.target.checked)}
                            />
                            Show the site name next to the logo
                        </label>
                        <p id="show_site_name_help" className="mt-1 text-sm text-gray-500">
                            Turn this off if the logo already contains the name. Without a logo, the name is always shown.
                        </p>
                        <InputError message={errors.show_site_name} className="mt-2" />
                    </div>
                </Section>

                <Section title="Look" description="The colour, fonts and shapes of the public site.">
                    <BrandColorField
                        value={data.brand_color}
                        onChange={(value) => setData('brand_color', value)}
                        error={errors.brand_color}
                        minContrast={options.minContrast}
                    />

                    <NavBackgroundField
                        value={data.nav_background_color}
                        onChange={(value) => setData('nav_background_color', value)}
                        error={errors.nav_background_color}
                        brandColor={data.brand_color}
                        siteName={data.site_name}
                    />

                    <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        {[
                            ['font_body', 'Body text font'],
                            ['font_heading', 'Heading font'],
                        ].map(([field, label]) => (
                            <div key={field}>
                                <InputLabel htmlFor={field} value={label} />
                                <select id={field} className={fieldClass} value={data[field]} onChange={(e) => setData(field, e.target.value)}>
                                    {options.fonts.map((font) => (
                                        <option key={font.value} value={font.value}>
                                            {font.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors[field]} className="mt-2" />
                            </div>
                        ))}
                    </div>

                    <ChoiceGroup legend="Corners" name="corners" options={options.corners} value={data.corners} onChange={(value) => setData('corners', value)} error={errors.corners} />
                    <ChoiceGroup legend="Buttons" name="buttons" options={options.buttons} value={data.buttons} onChange={(value) => setData('buttons', value)} error={errors.buttons} />

                    <ThemePreview
                        brand={data.brand_color}
                        radius={radius}
                        buttons={data.buttons}
                        fontLabels={`${fontLabel(data.font_body)} for text, ${fontLabel(data.font_heading)} for headings`}
                    />
                </Section>

                <Section
                    title="Navigation"
                    description="The links in the public site's header and footer, after Home. Each one goes to a published page or to an address: a path on this site, such as /blog, or another website."
                >
                    <NavigationEditor
                        pages={pages}
                        items={data.navigation}
                        onChange={(navigation) => setData('navigation', navigation)}
                        max={options.maxNavigation}
                        frontPageId={data.front_page_id === '' ? null : Number(data.front_page_id)}
                        errors={errors}
                    />
                </Section>

                <Section title="Front page">
                    <div>
                        <InputLabel htmlFor="front_page_id" value="The home page shows" />
                        <select
                            id="front_page_id"
                            className={fieldClass}
                            value={data.front_page_id}
                            aria-describedby="front_page_help"
                            onChange={(e) => setData('front_page_id', e.target.value)}
                        >
                            <option value="">Latest posts</option>
                            {pendingFrontPage && (
                                <option value={pendingFrontPage.id}>{pendingFrontPage.title} (not published)</option>
                            )}
                            {pages.map((page) => (
                                <option key={page.id} value={page.id}>
                                    {page.title}
                                </option>
                            ))}
                        </select>
                        <p id="front_page_help" className="mt-2 text-sm text-gray-600">
                            {frontPage
                                ? `The home page shows the page “${frontPage.title}”. The latest posts are still listed on the Blog page, and a page that stops being the front page stays at its own address.`
                                : 'The home page lists the newest blog posts. Choose a published page to show it there instead.'}{' '}
                            While the chosen page isn&rsquo;t published, the home page shows the latest posts; if it is deleted, it goes back to them for good.
                        </p>
                        {frontPageIsPending && (
                            <p role="status" className="mt-2 rounded-md border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800">
                                “{pendingFrontPage.title}” is the front page, but it isn&rsquo;t published, so the home page shows the
                                latest posts until it is.
                            </p>
                        )}
                        <InputError message={errors.front_page_id} className="mt-2" />
                    </div>
                </Section>

                <Section title="Footer">
                    <div>
                        <InputLabel htmlFor="footer_text" value="Footer text (optional)" />
                        <TextInput
                            id="footer_text"
                            className="mt-1 block w-full"
                            value={data.footer_text}
                            maxLength={200}
                            aria-describedby="footer_text_help footer_text_count"
                            onChange={(e) => setData('footer_text', e.target.value)}
                        />
                        <p id="footer_text_help" className="mt-1 text-sm text-gray-500">
                            One short line, for example an address or opening hours.
                        </p>
                        <CharacterCount id="footer_text_count" value={data.footer_text} max={200} />
                        <InputError message={errors.footer_text} className="mt-2" />
                    </div>
                </Section>

                <div className="flex items-center gap-4 px-4 sm:px-0">
                    <PrimaryButton disabled={processing}>{processing ? 'Saving…' : 'Save settings'}</PrimaryButton>
                    {Object.keys(errors).length > 0 && (
                        <p role="alert" className="text-sm text-danger-600">
                            Some settings need fixing. See the messages above.
                        </p>
                    )}
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
