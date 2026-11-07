import { __ } from '@wordpress/i18n';
import { InspectorControls } from '@wordpress/block-editor';

import './editor.scss';

/**
 * Render the editor preview for the Bob Go tracking block.
 *
 * @return {Element} Element to render.
 */
export default function edit() {
	return [
		<InspectorControls key="settting"></InspectorControls>,
		<div key="render" className="bobgo-tracking wp-admin">
			<div key="render" className="shipping-step-status">
				<header>
					<h2>{ __( 'Bob Go tracking details will go here:' ) }</h2>
					<h3>{ __( '…' ) }</h3>
				</header>
			</div>
		</div>,
	];
}
