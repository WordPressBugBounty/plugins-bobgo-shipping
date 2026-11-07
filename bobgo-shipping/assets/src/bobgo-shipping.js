/* global Mustache */
import sanitizeHtml from './sanitize-html';

const BobGoShipping = class {
	constructor( containerEle ) {
		this.containerEle = containerEle;
		this.formEle = this.containerEle.querySelector(
			'#mc-search-container'
		);
		this.submitBttn = this.formEle
			? this.formEle.querySelector( '#mc-track-button' )
			: null;
		this.validationEle = this.containerEle.querySelector( '.validation' );
		this.trackingContainer = this.containerEle.querySelector(
			'#mc-tracking-container'
		);
		this.errorContainer = this.containerEle.querySelector(
			'#mc-error-container'
		);
		this.loadingContainer = this.containerEle.querySelector(
			'#mc-tracking-loader'
		);
		this.trackNumberInput = this.formEle
			? this.formEle.querySelector( 'input[name=order-number]' )
			: null;

		// Both Mustache templates live inside the container they render into, so
		// rendering removes them from the DOM. Read the markup once, up front —
		// looking the elements up per render leaves a second lookup (or a second
		// error) with nothing to render.
		this.trackingTemplate = this.readTemplate( 'mc-tracking-template' );
		this.errorTemplate = this.readTemplate( 'mc-error-template' );

		this.abortController = new AbortController();
		this.hiddenClassName = 'mc-hidden';
		this.mustacheTagsOverride = [ '{{', '}}' ];
		this.fetchTimeoutMs = 30000; // 30 second timeout for API requests
		this.cacheDurationMs = 5 * 60 * 1000; // 5 minute cache for tracking data

		// Check for Intl.DateTimeFormat support (fallback for older browsers)
		this.hasIntlSupport =
			typeof Intl !== 'undefined' &&
			typeof Intl.DateTimeFormat !== 'undefined';

		// Date formatters using Intl.DateTimeFormat (replaces Moment.js)
		if ( this.hasIntlSupport ) {
			this.dateFormatter = new Intl.DateTimeFormat( 'en-GB', {
				day: 'numeric',
				month: 'short',
				year: 'numeric',
			} );
			this.timeFormatter = new Intl.DateTimeFormat( 'en-GB', {
				hour: '2-digit',
				minute: '2-digit',
				hour12: false,
			} );
			this.dateWithWeekdayFormatter = new Intl.DateTimeFormat( 'en-GB', {
				weekday: 'short',
				day: 'numeric',
				month: 'short',
				year: 'numeric',
			} );
		}

		// Check for order number in URL to decide which elements to show
		this.orderNR = this.getOrderNumberQueryParam();

		// Always bind the form submit handler. The form is shown again on
		// error, and without the handler the browser falls back to a default
		// form submission (page navigation) instead of triggering a fetch.
		this.handleFormSubmit();

		if ( this.orderNR ) {
			// Reflect the URL value in the input so the user can see and edit it.
			if ( this.trackNumberInput ) {
				this.trackNumberInput.value = this.orderNR;
			}
			this.fetchOrderData();
		} else {
			this.showElements();
		}
	}

	/**
	 * Read a Mustache template's markup out of the widget's DOM.
	 *
	 * @param {string} id - Element id of the template script tag.
	 * @return {string} The template markup, or an empty string if absent.
	 */
	readTemplate( id ) {
		const template = this.containerEle.querySelector( `#${ id }` );
		return template ? template.innerHTML : '';
	}

	handleFormSubmit() {
		// Select the form element
		this.formEle = this.containerEle.querySelector( '#tracking-form' );

		if ( this.formEle ) {
			// Listen for the form submit event
			this.formEle.addEventListener( 'submit', ( event ) => {
				event.preventDefault();

				// Get the value from the order number input field
				const orderInput = this.containerEle.querySelector(
					'input[name=order-number]'
				);
				if ( orderInput ) {
					// Sanitize order number - only allow alphanumeric, hyphens, underscores
					this.orderNR = this.sanitizeOrderNumber( orderInput.value );

					if ( ! this.orderNR ) {
						this.showError( 'Please enter a valid order number.' );
						return;
					}

					// Update the URL without reloading the page (Issue #11 - encode URL params)
					if ( history.pushState ) {
						const url = new URL( window.location.href );
						url.searchParams.set( 'order-number', this.orderNR );
						window.history.pushState(
							{ path: url.href },
							'',
							url.href
						);
					}

					// Fetch the order data using the entered order number
					this.fetchOrderData();
				}
			} );
		}
	}

	/**
	 * Sanitize order number to prevent injection attacks.
	 * Only allows alphanumeric characters, hyphens, and underscores.
	 *
	 * @param {string} orderNumber - The raw order number input
	 * @return {string} - Sanitized order number or empty string if invalid
	 */
	sanitizeOrderNumber( orderNumber ) {
		if ( ! orderNumber || typeof orderNumber !== 'string' ) {
			return '';
		}
		// Remove any characters that aren't alphanumeric, hyphen, or underscore
		const sanitized = orderNumber.trim().replace( /[^a-zA-Z0-9\-_]/g, '' );
		// Limit length to prevent DoS
		return sanitized.substring( 0, 100 );
	}

	getOrderNumberQueryParam() {
		const urlParams = new URLSearchParams( window.location.search );
		const orderNumber = urlParams.get( 'order-number' );
		// Sanitize the order number from URL to prevent XSS
		return orderNumber ? this.sanitizeOrderNumber( orderNumber ) : null;
	}

	hideElements() {
		// Hide the entire search container and validation message
		const searchContainer = this.containerEle.querySelector(
			'#mc-search-container'
		);
		if ( searchContainer ) {
			searchContainer.classList.add( this.hiddenClassName );
		}
		if ( this.validationEle ) {
			this.validationEle.classList.add( this.hiddenClassName );
		}

		const trackButton = document.getElementById( 'mc-track-button' );
		if ( trackButton ) {
			trackButton.classList.add( this.hiddenClassName );
		}
	}

	showElements() {
		// Show the entire search container and validation message
		const searchContainer = this.containerEle.querySelector(
			'#mc-search-container'
		);
		if ( searchContainer ) {
			searchContainer.classList.remove( this.hiddenClassName );
		}
		if ( this.validationEle ) {
			this.validationEle.classList.remove( this.hiddenClassName );
		}

		const trackButton = document.getElementById( 'mc-track-button' );
		if ( trackButton ) {
			trackButton.classList.remove( this.hiddenClassName );
		}
	}

	fetchOrderData() {
		// Check for cached data first
		const cachedData = this.getCachedData( this.orderNR );
		if ( cachedData ) {
			// Use cached data - much faster than API call
			this.shippingDetails = cachedData.map( ( shipment ) =>
				this.prepareForRender( shipment )
			);
			this.hideElements();
			this.displayOrderData();
			return;
		}

		if ( this.fetchRunning ) {
			this.abortController.abort();
			this.abortController = new AbortController();
		} else {
			this.fetchRunning = true;
		}

		if ( this.submitBttn ) {
			this.submitBttn.disabled = true;
		}
		this.showLoading();

		// Build URL safely with encoded parameters (Issue #11)
		const l10n =
			typeof bobgo_shipping_l10n !== 'undefined'
				? bobgo_shipping_l10n
				: null;
		if ( ! l10n ) {
			this.fetchRunning = false;
			if ( this.submitBttn ) {
				this.submitBttn.disabled = false;
			}
			this.hideLoading();
			return;
		}
		const encodedOrderNR = encodeURIComponent( this.orderNR );
		const encodedDomain = encodeURIComponent( l10n.domain );
		const url = l10n.v3_api_url
			.replace( 'NUMBER', encodedOrderNR )
			.replace( 'DOMAIN', encodedDomain );

		// Set up timeout for fetch request (Issue #10)
		const timeoutId = setTimeout( () => {
			this.abortController.abort();
		}, this.fetchTimeoutMs );

		// No custom headers here — they would trigger a CORS preflight on
		// this cross-origin request. The channel is sent as a query param
		// instead (see v3_api_url in class-shortcode.php).
		fetch( url, { signal: this.abortController.signal } )
			.then( ( response ) => {
				clearTimeout( timeoutId );
				if ( ! response.ok ) {
					throw new Error(
						`HTTP error! status: ${ response.status }`
					);
				}
				return response.json();
			} )
			.then( ( data ) => {
				// Check if we received valid order data
				if ( Array.isArray( data ) && data.length > 0 ) {
					// Cache the successful response for future page loads
					this.setCachedData( this.orderNR, data );

					// Prepare all shipments for rendering
					this.shippingDetails = data.map( ( shipment ) =>
						this.prepareForRender( shipment )
					);

					// Hide the search form elements after successfully fetching the data
					this.hideElements();

					// Display all fetched order data
					this.displayOrderData();
				} else {
					this.showTrackingError();
					this.showElements();
				}
			} )
			.catch( ( err ) => {
				clearTimeout( timeoutId );
				this.showTrackingError( err.name === 'AbortError' );
				this.showElements();
			} )
			.finally( () => {
				this.fetchRunning = false;
				if ( this.submitBttn ) {
					this.submitBttn.disabled = false;
				}
				this.hideLoading();
			} );
	}

	/**
	 * Escape HTML special characters to prevent XSS.
	 *
	 * @param {string} text - The text to escape
	 * @return {string} - Escaped text safe for HTML insertion
	 */
	escapeHtml( text ) {
		if ( ! text || typeof text !== 'string' ) {
			return '';
		}
		const div = document.createElement( 'div' );
		div.textContent = text;
		return div.innerHTML;
	}

	/**
	 * Get the cache key for a given order number.
	 *
	 * @param {string} orderNumber - The order number
	 * @return {string} - Cache key for sessionStorage
	 */
	getCacheKey( orderNumber ) {
		return `bobgo_tracking_${ orderNumber }`;
	}

	/**
	 * Get cached tracking data from sessionStorage.
	 *
	 * @param {string} orderNumber - The order number to look up
	 * @return {object|null} - Cached data or null if not found/expired
	 */
	getCachedData( orderNumber ) {
		try {
			const cacheKey = this.getCacheKey( orderNumber );
			const cached = sessionStorage.getItem( cacheKey );

			if ( ! cached ) {
				return null;
			}

			const { data, timestamp } = JSON.parse( cached );
			const now = Date.now();

			// Check if cache has expired
			if ( now - timestamp > this.cacheDurationMs ) {
				sessionStorage.removeItem( cacheKey );
				return null;
			}

			return data;
		} catch ( err ) {
			// sessionStorage might be unavailable or corrupted
			return null;
		}
	}

	/**
	 * Cache tracking data in sessionStorage.
	 *
	 * @param {string} orderNumber - The order number
	 * @param {Object} data        - The tracking data to cache
	 */
	setCachedData( orderNumber, data ) {
		try {
			const cacheKey = this.getCacheKey( orderNumber );
			const cacheEntry = {
				data,
				timestamp: Date.now(),
			};
			sessionStorage.setItem( cacheKey, JSON.stringify( cacheEntry ) );
		} catch ( err ) {
			// sessionStorage might be full or unavailable - fail silently
		}
	}

	/**
	 * Sanitize HTML to prevent XSS attacks (Issue #2).
	 * Delegates to shared sanitize-html utility.
	 *
	 * @param {string} html - The HTML string to sanitize
	 * @return {string} - Sanitized HTML safe for innerHTML
	 */
	sanitizeHtml( html ) {
		return sanitizeHtml( html );
	}

	/**
	 * Parse a UTC date string and return a local Date object.
	 * Replaces moment.utc(time).local()
	 *
	 * @param {string} dateString - UTC date string from API
	 * @return {Date|null} - Local Date object or null if invalid
	 */
	parseUtcToLocal( dateString ) {
		if ( ! dateString ) {
			return null;
		}
		const date = new Date( dateString );
		return isNaN( date.getTime() ) ? null : date;
	}

	/**
	 * Format a date as "D MMM YYYY" (e.g., "13 Jan 2026").
	 * Replaces moment().format("D MMM YYYY")
	 *
	 * @param {Date} date - Date object to format
	 * @return {string} - Formatted date string
	 */
	formatDate( date ) {
		if ( ! date || ! ( date instanceof Date ) || isNaN( date.getTime() ) ) {
			return '';
		}
		if ( this.hasIntlSupport ) {
			return this.dateFormatter.format( date );
		}
		// Fallback for older browsers
		const months = [
			'Jan',
			'Feb',
			'Mar',
			'Apr',
			'May',
			'Jun',
			'Jul',
			'Aug',
			'Sep',
			'Oct',
			'Nov',
			'Dec',
		];
		return `${ date.getDate() } ${
			months[ date.getMonth() ]
		} ${ date.getFullYear() }`;
	}

	/**
	 * Format a date's time as "HH:mm" (e.g., "14:30").
	 * Replaces moment().format("HH:mm")
	 *
	 * @param {Date} date - Date object to format
	 * @return {string} - Formatted time string
	 */
	formatTime( date ) {
		if ( ! date || ! ( date instanceof Date ) || isNaN( date.getTime() ) ) {
			return '';
		}
		if ( this.hasIntlSupport ) {
			return this.timeFormatter.format( date );
		}
		// Fallback for older browsers
		const hours = String( date.getHours() ).padStart( 2, '0' );
		const minutes = String( date.getMinutes() ).padStart( 2, '0' );
		return `${ hours }:${ minutes }`;
	}

	/**
	 * Format a date as "ddd, D MMM YYYY" (e.g., "Mon, 13 Jan 2026").
	 * Replaces moment().format("ddd, D MMM YYYY")
	 *
	 * @param {Date} date - Date object to format
	 * @return {string} - Formatted date string with weekday
	 */
	formatDateWithWeekday( date ) {
		if ( ! date || ! ( date instanceof Date ) || isNaN( date.getTime() ) ) {
			return '';
		}
		if ( this.hasIntlSupport ) {
			return this.dateWithWeekdayFormatter.format( date );
		}
		// Fallback for older browsers
		const days = [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ];
		const months = [
			'Jan',
			'Feb',
			'Mar',
			'Apr',
			'May',
			'Jun',
			'Jul',
			'Aug',
			'Sep',
			'Oct',
			'Nov',
			'Dec',
		];
		return `${ days[ date.getDay() ] }, ${ date.getDate() } ${
			months[ date.getMonth() ]
		} ${ date.getFullYear() }`;
	}

	/**
	 * Check if two dates are the same day.
	 * Replaces moment().isSame(date, 'day')
	 *
	 * @param {Date} date1 - First date
	 * @param {Date} date2 - Second date
	 * @return {boolean} - True if same day
	 */
	isSameDay( date1, date2 ) {
		if ( ! date1 || ! date2 ) {
			return false;
		}
		return date1.toDateString() === date2.toDateString();
	}

	getThemeColorPreference() {
		let contentBackground;

		// Check if bobgo_theme_settings provides content background
		if (
			typeof bobgo_theme_settings !== 'undefined' &&
			bobgo_theme_settings.contentBackgroundColor
		) {
			contentBackground =
				bobgo_theme_settings.contentBackgroundColor.desktop?.color;

			// If it's a palette reference, resolve it
			if (
				contentBackground &&
				contentBackground.startsWith( 'palette' )
			) {
				contentBackground = this.getPaletteColor( contentBackground );
			}
		}

		// Fallback to CSS variables or computed style
		if ( ! contentBackground ) {
			contentBackground =
				this.getCSSVariableBackgroundColor(
					'.content-wrap, .entry, .content-bg, .entry-content-wrap'
				) || getComputedStyle( document.body ).backgroundColor; // Fallback to body background
		}

		// If still no background color found, default to transparent (light theme assumption)
		if ( ! contentBackground || contentBackground === 'rgba(0, 0, 0, 0)' ) {
			return 'light';
		}

		let rgb;
		if ( contentBackground.startsWith( '#' ) ) {
			// Convert hex to RGB
			rgb = this.hexToRgb( contentBackground );
		} else {
			// Otherwise, assume it's in rgb or rgba format
			rgb = contentBackground.match( /\d+/g );
		}

		if ( ! rgb ) {
			return 'light'; // Default to light theme
		}

		const luminance =
			0.299 * rgb[ 0 ] + 0.587 * rgb[ 1 ] + 0.114 * rgb[ 2 ];
		return luminance < 128 ? 'dark' : 'light';
	}

	hexToRgb( hex ) {
		// Remove the hash at the start of the hex color if present
		hex = hex.replace( '#', '' );

		// Parse the hex values into RGB components
		const r = parseInt( hex.slice( 0, 2 ), 16 );
		const g = parseInt( hex.slice( 2, 4 ), 16 );
		const b = parseInt( hex.slice( 4, 6 ), 16 );

		return [ r, g, b ];
	}
	getPaletteColor( paletteName ) {
		// First, check for the global palette CSS variable (e.g., --global-paletteX)
		const globalCssVariableName = `--global-${ paletteName }`;

		const rootStyle = getComputedStyle( document.documentElement );
		let cssVariableValue = rootStyle
			.getPropertyValue( globalCssVariableName )
			?.trim();

		// If no global variable is found, fall back to the normal palette CSS variable (e.g., --paletteX)
		if ( ! cssVariableValue ) {
			const normalCssVariableName = `--${ paletteName }`;
			cssVariableValue = rootStyle
				.getPropertyValue( normalCssVariableName )
				?.trim();
		}

		if ( cssVariableValue ) {
			return cssVariableValue;
		}

		// If neither variable is found, return null or an empty fallback (depending on the use case)
		return null;
	}

	getCSSVariableBackgroundColor( selectors ) {
		const element = document.querySelector( selectors );
		if ( element ) {
			return getComputedStyle( element ).backgroundColor || null;
		}
		return null;
	}

	prepareForRender( record ) {
		record.groupedCheckpoints = record.grouped_checkpoints || [];
		record.hasTrackingEvents = record.groupedCheckpoints.length > 0;
		record.isCancelled =
			! record.hasTrackingEvents && record.status === 'cancelled';
		record.isPendingCollection =
			! record.hasTrackingEvents &&
			record.status === 'pending-collection';
		record.movementEvents = this.buildMovementEvents( record );
		record.trackingEvents = this.buildTrackingEvents( record );
		record.columns = record.movementEvents.length;
		record.cancelled = record.status === 'cancelled';
		record.locationType = record.pickup_point_location_type;
		record.pickupPointHeader = record.pickup_point_header;
		record.hasPickupPointDetails = record.show_pickup_point_details;
		record.hasLocationDescription =
			record.delivery_location &&
			record.delivery_location.description.length > 0;
		record.hasInstructions =
			record.show_pickup_point_details_instructions &&
			record.pickup_point_instructions &&
			record.pickup_point_instructions.length > 0;
		record.pickupPointInstructions = record.pickup_point_instructions;
		record.hasLocationImage =
			record.delivery_location &&
			record.delivery_location.image_url &&
			record.delivery_location.image_url.length > 0;
		return record;
	}

	/**
	 * Map sorted tracking step entries into render-ready event objects.
	 *
	 * @param {Array} sortedEvents - Sorted tracking step entries.
	 * @return {Array} Render-ready event objects.
	 */
	mapTrackingSteps( sortedEvents ) {
		return sortedEvents.map( ( event, index ) => {
			const parsedDate = this.parseUtcToLocal( event.time );
			return {
				status: event.label,
				image: event.image,
				imageStyle: event.image_style,
				date: parsedDate ? this.formatDate( parsedDate ) : null,
				time: parsedDate ? this.formatTime( parsedDate ) : null,
				shouldAddVerticalLine: index < sortedEvents.length - 1,
				hasDateAndTime: !! parsedDate,
			};
		} );
	}

	buildTrackingEvents( record ) {
		const sortedEvents =
			Object.entries( record.tracking_steps || {} )
				.map( ( [ key, value ] ) => ( { key, ...value } ) )
				.sort( ( a, b ) => {
					const timeA = a.time ? new Date( a.time ).getTime() : 0;
					const timeB = b.time ? new Date( b.time ).getTime() : 0;
					return (
						timeB - timeA ||
						Number( a.step_number ) - Number( b.step_number )
					);
				} ) || [];

		return this.mapTrackingSteps( sortedEvents );
	}

	buildMovementEvents( record ) {
		const sortedEvents =
			Object.entries( record.tracking_steps || {} )
				.map( ( [ key, value ] ) => ( { key, ...value } ) )
				.sort( ( a, b ) => a.step_number - b.step_number ) || [];

		return this.mapTrackingSteps( sortedEvents );
	}

	formatDeliveryRange( fromDateStr, toDateStr, earliestDateStr ) {
		const hasFrom = fromDateStr && fromDateStr.trim() !== '';
		const hasTo = toDateStr && toDateStr.trim() !== '';
		const hasEarliest = earliestDateStr && earliestDateStr.trim() !== '';

		if ( hasFrom && hasTo ) {
			const fromDate = new Date( fromDateStr );
			const toDate = new Date( toDateStr );

			const formattedFrom = this.formatDateWithWeekday( fromDate );
			const formattedTo = this.formatDateWithWeekday( toDate );

			return this.isSameDay( fromDate, toDate )
				? formattedTo
				: `${ formattedFrom } - ${ formattedTo }`;
		}

		if ( ! hasFrom && ! hasTo && hasEarliest ) {
			const earliestDate = new Date( earliestDateStr );
			return this.formatDateWithWeekday( earliestDate );
		}

		return '';
	}

	displayOrderData() {
		if ( ! this.trackingTemplate ) {
			return;
		}

		Mustache.parse( this.trackingTemplate );

		const themePreference = this.getThemeColorPreference();

		// Map through all shipments and prepare them for rendering
		const shipmentsData = this.shippingDetails.map( ( shipment ) => {
			// Determine the correct logo based on the theme
			const selectedLogo =
				themePreference === 'dark'
					? shipment.bob_go_logo_white
					: shipment.bob_go_logo_black;

			const deliveryRange = this.formatDeliveryRange(
				shipment.shipment_estimated_delivery_date_from,
				shipment.shipment_estimated_delivery_date_to,
				shipment.shipment_earliest_delivery_date
			);

			// Determine if we should show the delivery range
			const showEstimatedDeliveryRange = deliveryRange !== '';

			// Return the shipment object with the required template data
			return {
				...shipment,
				bobgo_logo: selectedLogo,
				deliveryRange,
				showEstimatedDeliveryRange,
			};
		} );

		// Prepare the data to pass to the Mustache template
		const templateData = {
			shipments: shipmentsData, // Array of all shipments
			show_branding:
				shipmentsData.length > 0
					? shipmentsData[ 0 ].show_branding
					: false, // Use branding info from the first shipment
			bobgo_logo:
				shipmentsData.length > 0 ? shipmentsData[ 0 ].bobgo_logo : null,
		};

		// Render the template with error boundary
		try {
			const rendered = Mustache.render(
				this.trackingTemplate,
				templateData
			);
			// Sanitize rendered HTML before insertion to prevent XSS (Issue #2)
			this.trackingContainer.innerHTML = this.sanitizeHtml( rendered );
			// Attach events and show tracking info
			this.attachClickEvents();
			this.showTracking();
		} catch ( err ) {
			this.showError(
				'Failed to render tracking information. Please try again later.'
			);
		}
	}

	attachClickEvents() {
		const directionLinks = document.querySelectorAll( '.directions-link' );
		directionLinks.forEach( ( link ) => {
			link.addEventListener( 'click', function () {
				const directionLat = this.getAttribute( 'direction-lat' );
				const directionLng = this.getAttribute( 'direction-lng' );

				// Validate lat/lng are numeric values and within valid ranges
				const latNum = parseFloat( directionLat );
				const lngNum = parseFloat( directionLng );

				// Latitude must be between -90 and 90, longitude between -180 and 180
				const isValidLat =
					! isNaN( latNum ) && latNum >= -90 && latNum <= 90;
				const isValidLng =
					! isNaN( lngNum ) && lngNum >= -180 && lngNum <= 180;

				if ( isValidLat && isValidLng ) {
					const url = `https://maps.google.com/?q=${ latNum },${ lngNum }`;
					window.open( url, '_blank' );
				}
			} );
		} );
	}

	showLoading() {
		this.loadingContainer.classList.remove( this.hiddenClassName );
		this.trackingContainer.classList.add( this.hiddenClassName );
		this.errorContainer.classList.add( this.hiddenClassName );
	}

	hideLoading() {
		this.loadingContainer.classList.add( this.hiddenClassName );
	}

	showTracking() {
		this.trackingContainer.classList.remove( this.hiddenClassName );
		this.errorContainer.classList.add( this.hiddenClassName );
	}

	/**
	 * Show a tracking-specific error with order number context.
	 *
	 * @param {boolean} isTimeout - Whether the error was due to a timeout.
	 */
	showTrackingError( isTimeout = false ) {
		const safeOrderNR = this.escapeHtml( this.orderNR );
		const message = isTimeout
			? `Request timed out while fetching tracking information for '${ safeOrderNR }'. Please try again.`
			: `We were unable to retrieve tracking information for '${ safeOrderNR }'. Please try again later.`;
		this.showError( message );
	}

	/**
	 * Display an error message to the user.
	 * Consolidated error display method (Issue #19 - unified error handling).
	 *
	 * @param {string} message - The error message to display
	 */
	showError( message ) {
		if ( ! this.errorTemplate ) {
			return;
		}

		// Escape the message to prevent XSS
		const safeMessage = this.escapeHtml( message );
		const rendered = Mustache.render( this.errorTemplate, {
			error: safeMessage,
		} );

		// Sanitize rendered HTML before insertion (Issue #2)
		this.errorContainer.innerHTML = this.sanitizeHtml( rendered );
		this.errorContainer.classList.remove( this.hiddenClassName );
		this.errorContainer.classList.add( 'mc-visible' );
	}
};

// Expose the class globally so it can be used anywhere
window.BobGoShipping = window.BobGoShipping || BobGoShipping;

// Prevent multiple init loops from stacking
( function () {
	if ( window.bobgo_init_started ) {
		return;
	}
	window.bobgo_init_started = true;

	function initBobGo( retries = 0 ) {
		const MAX_RETRIES = 100;
		const INTERVAL_MS = 100;

		const containerEle = document.querySelector( '#tracking-container' );
		const hasMustache = typeof window.Mustache !== 'undefined';
		const hasClass = typeof window.BobGoShipping !== 'undefined';

		if ( ! containerEle || ! hasMustache || ! hasClass ) {
			if ( retries < MAX_RETRIES ) {
				setTimeout( () => initBobGo( retries + 1 ), INTERVAL_MS );
			} else {
			}
			return;
		}

		// Prevent re-initialization if already initialized
		if ( containerEle.dataset.bobgoInitialized ) {
			return;
		}
		containerEle.dataset.bobgoInitialized = 'true';

		try {
			new window.BobGoShipping( containerEle );
		} catch ( err ) {}
	}

	initBobGo();
} )();
