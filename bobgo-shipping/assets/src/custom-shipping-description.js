/**
 * Custom Shipping Description for WooCommerce Checkout Blocks
 *
 * Uses MutationObserver for reliable DOM observation instead of retry loops.
 */
import sanitizeHtml from './sanitize-html';

( function () {
	'use strict';

	// Check if shipping description data is available
	if (
		typeof bobgo_shipping_description_data === 'undefined' ||
		! bobgo_shipping_description_data.shippingDescriptions
	) {
		return;
	}

	// Prevent multiple initializations
	if ( window.bobgo_shipping_description_initialized ) {
		return;
	}
	window.bobgo_shipping_description_initialized = true;

	const descriptions = bobgo_shipping_description_data.shippingDescriptions;
	let observer = null;
	let isProcessing = false;

	/**
	 * Apply styles to cart price elements.
	 */
	function styleCartPrices() {
		const cartPrices = document.querySelectorAll(
			'.wc-block-components-radio-control__description-group'
		);
		cartPrices.forEach( ( cartPrice ) => {
			if ( cartPrice && ! cartPrice.dataset.bobgoStyled ) {
				cartPrice.style.fontSize = '1.2rem';
				cartPrice.style.fontWeight = 'bold';
				cartPrice.dataset.bobgoStyled = 'true';
			}
		} );
	}

	/**
	 * Add custom descriptions to shipping options.
	 */
	function addShippingDescriptions() {
		const shippingOptions = document.querySelectorAll(
			'.wc-block-components-radio-control__option-layout'
		);

		if ( shippingOptions.length === 0 ) {
			return false;
		}

		shippingOptions.forEach( ( option ) => {
			const radioOption = option.closest(
				'.wc-block-components-radio-control__option'
			);
			if ( ! radioOption ) {
				return;
			}

			const radioInput = radioOption.querySelector(
				'input[type="radio"]'
			);
			if ( ! radioInput ) {
				return;
			}

			const methodId = radioInput.value;
			if ( ! descriptions[ methodId ] ) {
				return;
			}

			// Check if description already exists
			if ( option.querySelector( '.custom-shipping-description' ) ) {
				return;
			}

			// Create description element. Visual styling lives in
			// bobgo-shipping.scss (.bobgo-rate-meta); only the layout
			// hook (display: table-row) is set here as a defensive
			// fallback in case the stylesheet hasn't loaded yet.
			const descriptionDiv = document.createElement( 'div' );
			descriptionDiv.classList.add( 'custom-shipping-description' );
			descriptionDiv.style.display = 'table-row';
			// Sanitize HTML before insertion to prevent XSS (Issue #5)
			descriptionDiv.innerHTML = sanitizeHtml( descriptions[ methodId ] );

			// Insert after label group
			const labelGroup = option.querySelector(
				'.wc-block-components-radio-control__label-group'
			);
			if ( labelGroup ) {
				labelGroup.after( descriptionDiv );
			}
		} );

		return true;
	}

	/**
	 * Process shipping options - debounced to prevent multiple rapid calls.
	 */
	function processShippingOptions() {
		if ( isProcessing ) {
			return;
		}

		isProcessing = true;

		// Use requestAnimationFrame to batch DOM reads/writes
		requestAnimationFrame( () => {
			styleCartPrices();
			addShippingDescriptions();
			isProcessing = false;
		} );
	}

	/**
	 * Initialize the observer to watch for shipping options.
	 */
	function initObserver() {
		// Try to find the checkout/cart container
		const targetNode =
			document.querySelector( '.wc-block-checkout' ) ||
			document.querySelector( '.wc-block-cart' ) ||
			document.body;

		observer = new MutationObserver( ( mutations ) => {
			// Check if any mutation added nodes that might be shipping options
			const hasRelevantChanges = mutations.some( ( mutation ) => {
				if (
					mutation.type !== 'childList' ||
					mutation.addedNodes.length === 0
				) {
					return false;
				}

				return Array.from( mutation.addedNodes ).some( ( node ) => {
					if ( node.nodeType !== Node.ELEMENT_NODE ) {
						return false;
					}

					// Check if the added node contains shipping-related elements
					return (
						node.classList?.contains(
							'wc-block-components-radio-control__option'
						) ||
						node.querySelector?.(
							'.wc-block-components-radio-control__option'
						)
					);
				} );
			} );

			if ( hasRelevantChanges ) {
				processShippingOptions();
			}
		} );

		observer.observe( targetNode, {
			childList: true,
			subtree: true,
		} );
	}

	/**
	 * Cleanup observer to prevent memory leaks.
	 */
	function cleanup() {
		if ( observer ) {
			observer.disconnect();
			observer = null;
		}
	}

	/**
	 * Initialize on DOM ready.
	 */
	function init() {
		// Cleanup any existing observer before creating new one
		cleanup();

		// Process any existing options
		processShippingOptions();

		// Set up observer for future changes
		initObserver();

		// Cleanup on page unload to prevent memory leaks
		window.addEventListener( 'beforeunload', cleanup );

		// Also cleanup on SPA navigation (for themes using AJAX navigation)
		window.addEventListener( 'pagehide', cleanup );
	}

	// Wait for DOM to be ready
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
