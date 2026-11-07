import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import edit from './edit';
import bobgoIcon from './icon';

registerBlockType( 'bobgo/shipping', {
	title: __( 'Bob Go Tracking', 'bobgo-shipping' ),
	description: __(
		'Display Bob Go Shipping tracking info.',
		'bobgo-shipping'
	),
	category: 'widgets',
	icon: bobgoIcon,
	supports: { html: false },
	attributes: {
		bg_color: { type: 'string', default: '#000000' },
		text_color: { type: 'string', default: '#ffffff' },
	},
	/**
	 * @see ./edit.js
	 */
	edit,
	save: () => {
		return null; // we have a dynamic block, no custom save needed.
	},
} );
