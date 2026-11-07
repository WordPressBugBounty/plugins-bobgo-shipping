/**
 * Bob Go Admin Scripts
 *
 * @package
 */
( function () {
	'use strict';

	/**
	 * Get a localized string from the bobgo_admin object with fallback.
	 *
	 * @param {string} key      - The key to look up (tries bobgo_admin.i18n[key] then bobgo_admin[key]).
	 * @param {string} fallback - Fallback value if not found.
	 * @return {string}
	 */
	function i18n( key, fallback ) {
		if ( typeof bobgo_admin === 'undefined' ) {
			return fallback;
		}
		if ( bobgo_admin.i18n && bobgo_admin.i18n[ key ] ) {
			return bobgo_admin.i18n[ key ];
		}
		return bobgo_admin[ key ] || fallback;
	}

	/**
	 * Copy text to clipboard with button feedback
	 *
	 * @param {string}      text     - The text to copy
	 * @param {HTMLElement} buttonEl - The button element that was clicked
	 */
	window.bobgoCopyToClipboard = function ( text, buttonEl ) {
		if ( ! navigator.clipboard ) {
			// Fallback for older browsers
			const textarea = document.createElement( 'textarea' );
			textarea.value = text;
			textarea.style.position = 'fixed';
			textarea.style.opacity = '0';
			document.body.appendChild( textarea );
			textarea.select();
			try {
				document.execCommand( 'copy' );
				showCopyFeedback( buttonEl );
			} catch ( err ) {
				buttonEl.textContent = i18n( 'copyFailedText', 'Copy failed' );
			}
			document.body.removeChild( textarea );
			return;
		}
		navigator.clipboard.writeText( text ).then( function () {
			showCopyFeedback( buttonEl );
		} );
	};

	/**
	 * Dismiss a Bob Go notice with fade-out animation.
	 *
	 * @param {HTMLElement} notice - The .bobgo-notice element.
	 */
	function dismissNotice( notice ) {
		notice.classList.add( 'bobgo-notice--dismissing' );
		notice.addEventListener(
			'transitionend',
			function () {
				notice.remove();
			},
			{ once: true }
		);
		// Fallback removal if transitionend doesn't fire.
		setTimeout( function () {
			if ( notice.parentNode ) {
				notice.remove();
			}
		}, 300 );
	}

	// Bind dismiss buttons and auto-dismiss.
	document.addEventListener( 'DOMContentLoaded', function () {
		document
			.querySelectorAll( '.bobgo-notice__dismiss' )
			.forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					dismissNotice( btn.closest( '.bobgo-notice' ) );
				} );
			} );

		document
			.querySelectorAll( '.bobgo-notice[data-auto-dismiss]' )
			.forEach( function ( notice ) {
				const delay =
					parseInt(
						notice.getAttribute( 'data-auto-dismiss' ),
						10
					) || 4000;
				setTimeout( function () {
					dismissNotice( notice );
				}, delay );
			} );

		// Copy-to-clipboard buttons (replaces inline onclick handlers).
		// Pattern: <button data-bobgo-copy="literal text">  OR
		//         <button data-bobgo-copy-target="#elementId"> (copies textContent).
		document
			.querySelectorAll( '[data-bobgo-copy], [data-bobgo-copy-target]' )
			.forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					let text = btn.getAttribute( 'data-bobgo-copy' );
					if ( null === text ) {
						const targetSel = btn.getAttribute(
							'data-bobgo-copy-target'
						);
						const target = targetSel
							? document.querySelector( targetSel )
							: null;
						text = target ? target.textContent : '';
					}
					if ( window.bobgoCopyToClipboard ) {
						window.bobgoCopyToClipboard( text, btn );
					}
				} );
			} );

		// Confirmation prompt for any button carrying its own message.
		document.querySelectorAll( '[data-confirm]' ).forEach( ( btn ) => {
			btn.addEventListener( 'click', function ( e ) {
				const msg =
					btn.getAttribute( 'data-confirm' ) ||
					i18n(
						'disconnectConfirm',
						'Are you sure you want to disconnect?'
					);
				// eslint-disable-next-line no-alert -- intentional native confirm for destructive action
				if ( ! window.confirm( msg ) ) {
					e.preventDefault();
				}
			} );
		} );

		// Test-connection buttons (replaces inline scripts).
		// Pattern: <button data-bobgo-test="api|rates" data-action="<wp_ajax_action>"
		//                  data-nonce="<nonce>" data-result-target="#elementId">
		// Server may return a fresh nonce in result.data.nonce; we write it back to the button.
		document
			.querySelectorAll( '[data-bobgo-test]' )
			.forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					const action = btn.getAttribute( 'data-action' );
					const resultSel = btn.getAttribute( 'data-result-target' );
					const result = resultSel
						? document.querySelector( resultSel )
						: null;
					if ( ! action || ! result ) {
						return;
					}
					const mode = btn.getAttribute( 'data-bobgo-test' );

					const originalText = btn.textContent;
					btn.disabled = true;
					btn.textContent = i18n( 'testing', 'Testing…' );
					result.innerHTML = '';

					const formData = new FormData();
					formData.append( 'action', action );
					formData.append(
						'nonce',
						btn.getAttribute( 'data-nonce' ) || ''
					);
					formData.append(
						'context',
						btn.getAttribute( 'data-context' ) || ''
					);

					fetch( bobgo_admin.ajaxUrl, {
						method: 'POST',
						credentials: 'same-origin',
						body: formData,
					} )
						.then( function ( response ) {
							return response.text().then( function ( text ) {
								let json = null;
								try {
									json = JSON.parse( text );
								} catch ( e ) {
									json = null;
								}
								return { ok: response.ok, json };
							} );
						} )
						.then( function ( payload ) {
							const json = payload.json;
							if ( json && json.data && json.data.nonce ) {
								btn.setAttribute(
									'data-nonce',
									json.data.nonce
								);
							}
							renderTestResult(
								result,
								mode,
								payload.ok && json && json.success,
								json
							);
							refreshStatusRegion( btn, json );
						} )
						.catch( function () {
							renderTestResult( result, mode, false, null );
						} )
						.finally( function () {
							btn.disabled = false;
							btn.textContent = originalText;
						} );
				} );
			} );

		// API environment select → live-update the displayed base URL.
		const envSelect = document.getElementById( 'bobgo_api_env' );
		const envUrl = document.getElementById( 'bobgo_api_env_url' );
		if ( envSelect && envUrl ) {
			envSelect.addEventListener( 'change', function () {
				const opt = envSelect.options[ envSelect.selectedIndex ];
				const url = opt ? opt.getAttribute( 'data-url' ) : '';
				if ( url ) {
					envUrl.textContent = url;
				}
			} );
		}

		// Notifications handler radios → show the duplicate-email warning only
		// when Bob Go is selected to handle notifications.
		bindRadioToggle(
			'bobgo_email_notifications',
			function ( selectedValue ) {
				const warning = document.querySelector(
					'[data-bobgo-notifications-warning]'
				);
				if ( warning ) {
					warning.style.display =
						'bobgo' === selectedValue ? '' : 'none';
				}
			}
		);

		// Vendor plugin radios → reflect the Dokan detection status for the
		// current selection without requiring a save or page refresh.
		bindRadioToggle(
			'bobgo_vendor[vendor_plugin]',
			function ( selectedValue ) {
				const isDokan = 'dokan' === selectedValue;
				const dokanActive =
					typeof bobgo_admin !== 'undefined' &&
					bobgo_admin.dokanActive;
				const active = document.querySelector(
					'[data-bobgo-dokan-status="active"]'
				);
				const inactive = document.querySelector(
					'[data-bobgo-dokan-status="inactive"]'
				);
				if ( active ) {
					active.style.display = isDokan && dokanActive ? '' : 'none';
				}
				if ( inactive ) {
					inactive.style.display =
						isDokan && ! dokanActive ? '' : 'none';
				}
			}
		);
	} );

	/**
	 * Bind a change handler to a radio group, invoking it with the currently
	 * selected value. Runs once on bind so the UI matches the initial state.
	 *
	 * @param {string}   name    - The radio group's name attribute.
	 * @param {Function} onValue - Called with the selected value (or null).
	 */
	function bindRadioToggle( name, onValue ) {
		const radios = document.querySelectorAll(
			'input[name="' + name + '"]'
		);
		if ( ! radios.length ) {
			return;
		}
		function sync() {
			const selected = document.querySelector(
				'input[name="' + name + '"]:checked'
			);
			onValue( selected ? selected.value : null );
		}
		radios.forEach( function ( radio ) {
			radio.addEventListener( 'change', sync );
		} );
		sync();
	}

	/**
	 * Render the result of a test-connection request.
	 *
	 * @param {HTMLElement} container - Element that receives the result markup.
	 * @param {string}      mode      - 'api' renders a Bob Go notice; 'rates' inlines server HTML.
	 * @param {boolean}     success   - Whether the server reported success.
	 * @param {Object|null} json      - Parsed JSON response, or null if none.
	 */
	function renderTestResult( container, mode, success, json ) {
		if ( 'rates' === mode ) {
			const html = json && json.data && json.data.html;
			if ( success && html ) {
				container.innerHTML = html;
				return;
			}
			if ( html ) {
				// Server-rendered error markup is trusted (admin-only, escaped server-side).
				container.innerHTML = html;
				return;
			}
			container.innerHTML = '';
			container.appendChild(
				buildNotice(
					'error',
					i18n( 'failedToTest', 'Failed to test connection.' )
				)
			);
			return;
		}

		// 'api' mode.
		const variant = success ? 'success' : 'error';
		let message =
			json && json.data && json.data.message ? json.data.message : '';
		if ( ! message && ! success ) {
			message = i18n(
				'sessionExpired',
				'Your session has expired. Please refresh the page and try again.'
			);
		}
		container.innerHTML = '';
		container.appendChild( buildNotice( variant, message ) );
	}

	/**
	 * Swap in server-rendered status markup that the test just invalidated.
	 *
	 * A connection test records what it proved, so the Overview's problem banner
	 * and Connection card are stale the moment it returns — they were rendered
	 * before the test ran. The server sends back a fresh copy of that region and
	 * it is replaced here, so the page does not sit there contradicting the
	 * result printed underneath it until someone reloads.
	 *
	 * Silent when either side is absent: the Rates tab sends no refresh target,
	 * and a failed request carries no markup. The result notice still renders.
	 *
	 * @param {HTMLElement} btn  - The button that ran the test.
	 * @param {Object|null} json - Parsed JSON response, or null if none.
	 */
	function refreshStatusRegion( btn, json ) {
		const target = btn.getAttribute( 'data-refresh-target' );
		const html = json && json.data && json.data.statusHtml;
		if ( ! target || ! html ) {
			return;
		}

		const region = document.querySelector( target );
		if ( region ) {
			// Server-rendered admin markup, escaped server-side.
			region.innerHTML = html;
		}
	}

	/**
	 * Build a Bob Go notice element.
	 *
	 * @param {string} variant - 'success' or 'error'.
	 * @param {string} message - Text content for the message (text-only, no HTML).
	 * @return {HTMLElement}
	 */
	function buildNotice( variant, message ) {
		const icon =
			'success' === variant ? 'dashicons-yes-alt' : 'dashicons-warning';
		const notice = document.createElement( 'div' );
		notice.className = 'bobgo-notice bobgo-notice--' + variant;
		notice.setAttribute( 'role', 'alert' );
		const iconEl = document.createElement( 'span' );
		iconEl.className = 'bobgo-notice__icon dashicons ' + icon;
		const msgEl = document.createElement( 'p' );
		msgEl.className = 'bobgo-notice__message';
		msgEl.textContent = message;
		notice.appendChild( iconEl );
		notice.appendChild( msgEl );
		return notice;
	}

	/**
	 * Show copy feedback on button
	 *
	 * @param {HTMLElement} buttonEl - The button element
	 */
	function showCopyFeedback( buttonEl ) {
		const originalText = buttonEl.textContent;
		buttonEl.textContent = i18n( 'copiedText', 'Copied!' );
		buttonEl.disabled = true;
		setTimeout( function () {
			buttonEl.textContent = originalText;
			buttonEl.disabled = false;
		}, 1500 );
	}

	/**
	 * Custom Order Fields — repeater table logic.
	 * Only initialises when the table is on the page (Advanced tab).
	 */
	document.addEventListener( 'DOMContentLoaded', function () {
		const table = document.getElementById( 'bobgo-custom-fields-table' );
		if ( ! table ) {
			return;
		}

		const tbody = document.getElementById( 'bobgo-custom-fields-tbody' );
		const template = document.getElementById(
			'bobgo-custom-field-template'
		);
		const addBtn = document.getElementById( 'bobgo-add-field' );
		const refreshBtn = document.getElementById( 'bobgo-refresh-keys' );
		const counter = document.getElementById( 'bobgo-field-counter' );
		const maxFields =
			typeof bobgo_admin !== 'undefined' && bobgo_admin.maxCustomFields
				? bobgo_admin.maxCustomFields
				: 10;

		/**
		 * Get the current nonce, updating it if a fresh one was returned.
		 */
		function getNonce() {
			return typeof bobgo_admin !== 'undefined' &&
				bobgo_admin.customFieldsNonce
				? bobgo_admin.customFieldsNonce
				: '';
		}

		/**
		 * Update the stored nonce from an AJAX response.
		 * @param data
		 */
		function updateNonce( data ) {
			if ( data && data.nonce && typeof bobgo_admin !== 'undefined' ) {
				bobgo_admin.customFieldsNonce = data.nonce;
			}
		}

		/**
		 * Count current rows and update UI state.
		 */
		function updateState() {
			const rows = tbody.querySelectorAll( '.bobgo-custom-field-row' );
			const count = rows.length;

			// Update counter.
			if ( counter ) {
				const text = i18n( 'fieldsUsed', '%1$d of %2$d fields used' );
				counter.textContent = text
					.replace( '%1$d', count )
					.replace( '%2$d', maxFields )
					.replace( '%d', count )
					.replace( '%d', maxFields );
			}

			// Toggle add button.
			if ( addBtn ) {
				addBtn.disabled = count >= maxFields;
			}
		}

		/**
		 * Re-index row names after add/remove so PHP receives sequential indices.
		 */
		function reindexRows() {
			const rows = tbody.querySelectorAll( '.bobgo-custom-field-row' );
			rows.forEach( function ( row, i ) {
				const select = row.querySelector( '.bobgo-cf-key-select' );
				const input = row.querySelector( '.bobgo-cf-label-input' );
				const destination = row.querySelector(
					'.bobgo-cf-destination-select'
				);
				if ( select ) {
					select.name = 'bobgo_custom_fields[' + i + '][field_key]';
				}
				if ( input ) {
					input.name = 'bobgo_custom_fields[' + i + '][label]';
				}
				if ( destination ) {
					destination.name =
						'bobgo_custom_fields[' + i + '][destination]';
				}
			} );
		}

		/**
		 * Convert a meta key to a human-readable label (client-side mirror).
		 * @param key
		 */
		function metaKeyToLabel( key ) {
			// Strip ALL leading underscores to match the PHP meta_key_to_label()
			// helper (ltrim($key, '_')), so the JS preview and the server-side
			// fallback agree for keys like "__order_total".
			let label = key.replace( /^_+/, '' );
			label = label.replace( /[_-]/g, ' ' );
			return label.replace( /\b\w/g, function ( c ) {
				return c.toUpperCase();
			} );
		}

		/**
		 * Fetch and display a sample value for a meta key.
		 * @param select
		 */
		function fetchSample( select ) {
			const row = select.closest( '.bobgo-custom-field-row' );
			const sampleEl = row
				? row.querySelector( '.bobgo-sample-value' )
				: null;
			const key = select.value;

			if ( ! sampleEl ) {
				return;
			}

			if ( ! key ) {
				sampleEl.textContent = '—';
				return;
			}

			sampleEl.textContent = i18n( 'loading', 'Loading…' );

			const formData = new FormData();
			formData.append( 'action', 'bobgo_get_meta_sample' );
			formData.append( 'nonce', getNonce() );
			formData.append( 'meta_key', key );

			fetch( bobgo_admin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData,
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( result ) {
					if ( result.success && result.data ) {
						updateNonce( result.data );
						sampleEl.textContent =
							result.data.sample ||
							i18n( 'noSample', 'No sample available' );
					} else {
						if ( result.data ) {
							updateNonce( result.data );
						}
						sampleEl.textContent = i18n(
							'noSample',
							'No sample available'
						);
					}
				} )
				.catch( function () {
					sampleEl.textContent = '—';
				} );
		}

		/**
		 * Bind events on a single row (select change, remove button).
		 * @param row
		 */
		function bindRowEvents( row ) {
			const select = row.querySelector( '.bobgo-cf-key-select' );
			const labelInput = row.querySelector( '.bobgo-cf-label-input' );
			const removeBtn = row.querySelector( '.bobgo-remove-field' );

			if ( select ) {
				select.addEventListener( 'change', function () {
					// Keep the label in sync with the key while it is still
					// auto-generated — i.e. empty, or previously auto-filled and
					// not since edited by the user. A label the user typed
					// themselves (or one already saved on a pre-populated row) is
					// left untouched. See QA #1.
					if (
						labelInput &&
						select.value &&
						( ! labelInput.value.trim() ||
							labelInput.dataset.autofilled === '1' )
					) {
						labelInput.value = metaKeyToLabel( select.value );
						labelInput.dataset.autofilled = '1';
					}
					fetchSample( select );
				} );
			}

			if ( labelInput ) {
				labelInput.addEventListener( 'input', function () {
					// Once the user edits the label, stop tracking the key.
					labelInput.dataset.autofilled = '';
				} );
			}

			if ( removeBtn ) {
				removeBtn.addEventListener( 'click', function () {
					row.remove();
					reindexRows();
					updateState();
				} );
			}
		}

		/**
		 * Add a new field row from the template.
		 */
		function addRow() {
			const rows = tbody.querySelectorAll( '.bobgo-custom-field-row' );
			if ( rows.length >= maxFields ) {
				return;
			}

			const newIndex = rows.length;
			const fragment = template.content.cloneNode( true );
			const row = fragment.querySelector( 'tr' );

			// Replace __INDEX__ placeholders in name attributes.
			const select = row.querySelector( '.bobgo-cf-key-select' );
			const input = row.querySelector( '.bobgo-cf-label-input' );
			const destination = row.querySelector(
				'.bobgo-cf-destination-select'
			);
			if ( select ) {
				select.name = select.name.replace( '__INDEX__', newIndex );
			}
			if ( input ) {
				input.name = input.name.replace( '__INDEX__', newIndex );
			}
			if ( destination ) {
				destination.name = destination.name.replace(
					'__INDEX__',
					newIndex
				);
			}

			bindRowEvents( row );
			tbody.appendChild( fragment );
			updateState();
		}

		/**
		 * Refresh all meta key dropdowns via AJAX.
		 */
		function refreshKeys() {
			if ( ! refreshBtn ) {
				return;
			}

			refreshBtn.disabled = true;
			refreshBtn.classList.add( 'bobgo-spin' );

			const formData = new FormData();
			formData.append( 'action', 'bobgo_refresh_meta_keys' );
			formData.append( 'nonce', getNonce() );

			fetch( bobgo_admin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData,
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( result ) {
					if ( result.success && result.data && result.data.keys ) {
						updateNonce( result.data );
						updateSelectOptions( result.data.keys );
					} else if ( result.data ) {
						updateNonce( result.data );
					}
				} )
				.catch( function () {} )
				.finally( function () {
					refreshBtn.disabled = false;
					refreshBtn.classList.remove( 'bobgo-spin' );
				} );
		}

		/**
		 * Update all select dropdowns with fresh keys, preserving current selections.
		 * @param keys
		 */
		function updateSelectOptions( keys ) {
			const selects = table.querySelectorAll( '.bobgo-cf-key-select' );
			const placeholder = i18n( 'selectMetaKey', '— Select meta key —' );

			selects.forEach( function ( select ) {
				const current = select.value;

				// Clear existing options.
				select.innerHTML = '';

				// Add placeholder.
				const opt = document.createElement( 'option' );
				opt.value = '';
				opt.textContent = placeholder;
				select.appendChild( opt );

				// Add fresh keys.
				keys.forEach( function ( key ) {
					const option = document.createElement( 'option' );
					option.value = key;
					option.textContent = key;
					if ( key === current ) {
						option.selected = true;
					}
					select.appendChild( option );
				} );

				// If current value not in new keys, keep it.
				if ( current && select.value !== current ) {
					const extra = document.createElement( 'option' );
					extra.value = current;
					extra.textContent = current;
					extra.selected = true;
					select.appendChild( extra );
				}
			} );

			// Also update the template.
			if ( template ) {
				const tplSelect = template.content.querySelector(
					'.bobgo-cf-key-select'
				);
				if ( tplSelect ) {
					tplSelect.innerHTML = '';
					const tplOpt = document.createElement( 'option' );
					tplOpt.value = '';
					tplOpt.textContent = placeholder;
					tplSelect.appendChild( tplOpt );
					keys.forEach( function ( key ) {
						const option = document.createElement( 'option' );
						option.value = key;
						option.textContent = key;
						tplSelect.appendChild( option );
					} );
				}
			}
		}

		// Bind events on existing rows.
		tbody
			.querySelectorAll( '.bobgo-custom-field-row' )
			.forEach( bindRowEvents );

		// Bind toolbar buttons.
		if ( addBtn ) {
			addBtn.addEventListener( 'click', addRow );
		}
		if ( refreshBtn ) {
			refreshBtn.addEventListener( 'click', refreshKeys );
		}

		// Load samples for pre-populated rows.
		tbody
			.querySelectorAll( '.bobgo-cf-key-select' )
			.forEach( function ( select ) {
				if ( select.value ) {
					fetchSample( select );
				}
			} );

		// Initial state.
		updateState();
	} );
} )();
