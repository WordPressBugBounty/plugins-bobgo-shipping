/**
 * Sanitize HTML before insertion via innerHTML.
 *
 * Defense in depth for the tracking widget: API responses go through a
 * Mustache template and are then handed to innerHTML. Even though the
 * upstream is trusted, we strip anything that could execute script or
 * exfiltrate data if a payload were ever malformed or compromised.
 *
 * Strategy (allowlist where it matters, deny-by-default elsewhere):
 *   - <script>, <iframe>, <object>, <embed>, <link>, <meta>, <base> removed.
 *   - Any attribute starting with "on" is removed (event handlers).
 *   - href / src / xlink:href / formaction are validated against a small
 *     allowlist of safe schemes (http, https, mailto, tel) plus
 *     site-relative paths and fragments.
 *   - srcset is removed — it would need full URL parsing to validate.
 *   - style is kept, minus any declaration whose value fetches a remote
 *     resource (see sanitizeStyleAttribute).
 *
 * @param {string} html - The HTML string to sanitize.
 * @return {string} Sanitized HTML safe for innerHTML.
 */

const FORBIDDEN_TAGS = [
	'script',
	'iframe',
	'object',
	'embed',
	'link',
	'meta',
	'base',
];

const URL_ATTRS = [ 'href', 'src', 'xlink:href', 'formaction' ];

const STRIPPED_ATTRS = [ 'srcset' ];

const SAFE_URL_RE = /^(?:(?:https?|mailto|tel):|[/#?])/i;

// Values that make the browser request something. url() covers
// background-image / cursor / list-style-image / filter; image-set() can name a
// resource without url(). Both are normalised by CSSOM serialisation, so this
// runs against the parsed value rather than the raw attribute text.
const RESOURCE_VALUE_RE = /url\s*\(|image-set\s*\(/i;

function isSafeUrl( value ) {
	if ( typeof value !== 'string' ) {
		return false;
	}
	const trimmed = value.trim();
	if ( trimmed === '' ) {
		return false;
	}
	return SAFE_URL_RE.test( trimmed );
}

/**
 * Drop the unsafe declarations from an element's style attribute, keeping the
 * rest. The tracking API supplies inline styles for the milestone icons, so the
 * attribute cannot simply be deleted — but its declarations only ever reach the
 * page through CSSOM, which cannot inject markup or execute script. The browser
 * has already discarded anything it considers invalid (legacy expression() and
 * behavior vectors among them) by the time we read el.style, leaving
 * resource-fetching values as the one thing worth removing here.
 *
 * @param {Element} el - Element whose style attribute should be filtered.
 */
function sanitizeStyleAttribute( el ) {
	const unsafeProps = [];

	for ( let i = 0; i < el.style.length; i++ ) {
		const prop = el.style.item( i );
		if ( RESOURCE_VALUE_RE.test( el.style.getPropertyValue( prop ) ) ) {
			unsafeProps.push( prop );
		}
	}

	unsafeProps.forEach( ( prop ) => el.style.removeProperty( prop ) );

	if ( el.style.length === 0 ) {
		el.removeAttribute( 'style' );
	}
}

export default function sanitizeHtml( html ) {
	if ( ! html || typeof html !== 'string' ) {
		return '';
	}

	const temp = document.createElement( 'div' );
	temp.innerHTML = html;

	FORBIDDEN_TAGS.forEach( ( tag ) => {
		temp.querySelectorAll( tag ).forEach( ( el ) => el.remove() );
	} );

	temp.querySelectorAll( '*' ).forEach( ( el ) => {
		// Snapshot attribute names — removeAttribute mutates the live list.
		const names = Array.from( el.attributes, ( attr ) => attr.name );

		names.forEach( ( name ) => {
			const lower = name.toLowerCase();

			if ( lower.startsWith( 'on' ) ) {
				el.removeAttribute( name );
				return;
			}

			if ( STRIPPED_ATTRS.includes( lower ) ) {
				el.removeAttribute( name );
				return;
			}

			if ( lower === 'style' ) {
				sanitizeStyleAttribute( el );
				return;
			}

			if ( URL_ATTRS.includes( lower ) ) {
				const value = el.getAttribute( name );
				if ( ! isSafeUrl( value ) ) {
					el.removeAttribute( name );
				}
			}
		} );
	} );

	return temp.innerHTML;
}
