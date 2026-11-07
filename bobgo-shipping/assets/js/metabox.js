/**
 * Bob Go Meta Box Scripts
 *
 * Handles re-sync AJAX and collapsible timeline sections.
 *
 * @package
 */
( function () {
	'use strict';

	/** Localized data object (set via wp_localize_script). */
	const metaboxData =
		typeof bobgo_metabox !== 'undefined' ? bobgo_metabox : {};

	/** Get the AJAX URL from localized data or WordPress globals. */
	function getAjaxUrl() {
		return (
			metaboxData.ajaxUrl ||
			( typeof ajaxurl !== 'undefined'
				? ajaxurl
				: '/wp-admin/admin-ajax.php' )
		);
	}

	/**
	 * Initialise meta box interactivity once DOM is ready.
	 */
	document.addEventListener( 'DOMContentLoaded', function () {
		initTimelineToggles();
		initResyncButtons();
		initBulkResync();
	} );

	/**
	 * Set up collapsible timeline sections.
	 */
	function initTimelineToggles() {
		const toggles = document.querySelectorAll( '.bobgo-timeline-toggle' );
		for ( let i = 0; i < toggles.length; i++ ) {
			toggles[ i ].addEventListener( 'click', function () {
				const index = this.getAttribute( 'data-index' );
				const timeline = document.getElementById(
					'bobgo-timeline-' + index
				);
				if ( ! timeline ) {
					return;
				}

				const isOpen = timeline.style.display !== 'none';
				timeline.style.display = isOpen ? 'none' : 'block';
				this.classList.toggle( 'bobgo-open', ! isOpen );

				// Update arrow character.
				const text = this.textContent.trim();
				if ( isOpen ) {
					this.innerHTML =
						'&#9654; ' + text.replace( /^[^\s]+\s/, '' );
				} else {
					this.innerHTML =
						'&#9660; ' + text.replace( /^[^\s]+\s/, '' );
				}
			} );
		}
	}

	/**
	 * Set up re-sync button AJAX handlers.
	 */
	function initResyncButtons() {
		const buttons = document.querySelectorAll( '.bobgo-resync-btn' );
		for ( let i = 0; i < buttons.length; i++ ) {
			buttons[ i ].addEventListener( 'click', handleResync );
		}
	}

	/**
	 * Set up bulk re-sync link for failed orders notice.
	 */
	function initBulkResync() {
		const link = document.querySelector( '.bobgo-bulk-resync-link' );
		if ( ! link ) {
			return;
		}

		link.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			const el = e.currentTarget;
			const nonce = el.getAttribute( 'data-nonce' );
			if ( ! nonce ) {
				return;
			}

			el.textContent = 'Re-syncing...';
			el.style.pointerEvents = 'none';

			const url = getAjaxUrl();

			const formData = new FormData();
			formData.append( 'action', 'bobgo_bulk_resync_failed' );
			formData.append( 'nonce', nonce );

			fetch( url, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData,
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( data ) {
					if ( data.success ) {
						const notice = el.closest(
							'.bobgo-failed-sync-notice'
						);
						if ( notice ) {
							notice.querySelector( 'p' ).textContent =
								data.data.message;
							setTimeout( function () {
								notice.style.display = 'none';
							}, 3000 );
						}
					} else {
						const msg =
							data.data && data.data.message
								? data.data.message
								: 'Re-sync failed';
						el.textContent = msg;
						el.style.pointerEvents = '';
					}
				} )
				.catch( function () {
					el.textContent = 'Error — please try again';
					el.style.pointerEvents = '';
				} );
		} );
	}

	/**
	 * Handle re-sync button click.
	 *
	 * @param {Event} event Click event.
	 */
	function handleResync( event ) {
		const btn = event.currentTarget;
		const orderId = btn.getAttribute( 'data-order-id' );
		if ( ! orderId ) {
			return;
		}

		const originalText = btn.innerHTML;
		btn.disabled = true;
		btn.textContent = metaboxData.syncingText || 'Syncing...';

		const nonce = metaboxData.nonce || '';
		const url = getAjaxUrl();

		const formData = new FormData();
		formData.append( 'action', 'bobgo_resync_order' );
		formData.append( 'order_id', orderId );
		formData.append( 'nonce', nonce );

		fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				if ( data.success ) {
					btn.textContent = metaboxData.syncedText || 'Synced!';
					// Reload after short delay to show updated data.
					setTimeout( function () {
						location.reload();
					}, 1000 );
				} else {
					const msg =
						data.data && data.data.message
							? data.data.message
							: 'Sync failed';
					btn.textContent = msg;
					setTimeout( function () {
						btn.innerHTML = originalText;
						btn.disabled = false;
					}, 3000 );
				}
			} )
			.catch( function () {
				btn.textContent = 'Error';
				setTimeout( function () {
					btn.innerHTML = originalText;
					btn.disabled = false;
				}, 3000 );
			} );
	}
} )();
