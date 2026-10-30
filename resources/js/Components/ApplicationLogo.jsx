/**
 * The Alodium mark, the same drawing as astro/src/assets/alodium-mark.svg
 * (only its viewBox and path: the file also holds an 8 KB content credential).
 * Drawn in currentColor, so set the colour with a text-* class: text-on-nav in
 * the navigation bar, text-primary on the login page. public/favicon.svg and
 * favicon.ico are copies of the public site's.
 */
export default function ApplicationLogo(props) {
    return (
        <svg viewBox="11 10 1024 997" xmlns="http://www.w3.org/2000/svg" fill="currentColor" role="img" aria-label="Alodium" {...props}>
            <path fillRule="evenodd" d="M523.101 10.4127L1035.1 503.511L784.006 722.121V1006.41H636.879V849.562L523.101 947.594L409.324 849.562V1006.41H262.197V722.121L11.1014 503.511L523.101 10.4127ZM815.393 502.531L523.101 220.2L230.81 502.531L523.101 757.413L815.393 502.531Z" />
        </svg>
    );
}
