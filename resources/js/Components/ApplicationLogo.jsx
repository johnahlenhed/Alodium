/**
 * The Alodium mark in the admin, the same drawing as public/favicon.svg (its
 * viewBox and path only). Drawn in currentColor rather than the favicon's fixed
 * colour, so set the colour with a text-* class: text-on-nav in the navigation
 * bar, text-primary on the login page. The public site's mark is separate
 * (astro/src/assets/alodium-mark.svg).
 */
export default function ApplicationLogo(props) {
    return (
        <svg viewBox="0 0 240 240" xmlns="http://www.w3.org/2000/svg" fill="currentColor" role="img" aria-label="Alodium" {...props}>
            <path
                fillRule="evenodd"
                d="M62.72,8.0 L61.87,71.73 L8.0,118.96 L61.87,166.28 L62.72,230.01 L94.02,230.01 L95.25,195.49 L119.24,215.69 L144.56,195.49 L145.8,230.01 L177.09,230.01 L177.94,166.37 L232.0,119.05 L177.94,71.54 L177.09,8.0 L145.8,8.0 L144.56,42.52 L120.76,22.23 L95.25,42.52 L94.02,8.0 Z M119.43,64.52 L183.07,118.96 L120.57,173.49 L56.93,119.05 Z"
            />
        </svg>
    );
}
