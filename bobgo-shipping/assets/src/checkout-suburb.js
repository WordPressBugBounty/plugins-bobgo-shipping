document.addEventListener( 'DOMContentLoaded', function () {
	let debounceTimer = null;
	const DEBOUNCE_MS = 300;

	/**
	 * Attaches change detection to a suburb field.
	 * Only triggers checkout update if the value actually changed.
	 * Uses debouncing to prevent multiple rapid AJAX calls.
	 * @param field
	 */
	function attachSuburbChangeHandler( field ) {
		if ( ! field ) {
			return;
		}

		let previousValue = field.value;

		field.addEventListener( 'focus', function () {
			previousValue = field.value;
		} );

		field.addEventListener( 'blur', function () {
			if ( field.value !== previousValue ) {
				if ( debounceTimer ) {
					clearTimeout( debounceTimer );
				}
				debounceTimer = setTimeout( function () {
					const event = new CustomEvent( 'update_checkout' );
					document.body.dispatchEvent( event );
					debounceTimer = null;
				}, DEBOUNCE_MS );
			}
		} );
	}

	attachSuburbChangeHandler( document.getElementById( 'shipping_suburb' ) );
	attachSuburbChangeHandler( document.getElementById( 'billing_suburb' ) );
} );
