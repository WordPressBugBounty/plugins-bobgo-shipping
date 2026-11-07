<?php
/**
 * Tracking widget template.
 *
 * @package BobGo_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="tracking-container" class="bobgo-tracking">
	<!-- Search form for tracking number -->
	<div id="mc-search-container" class="mc-hidden bobgo-card">
		<div>
			<div>
				<h2>Please enter your order number or tracking reference below.</h2>
			</div>
			<div class="clearfix">&nbsp;</div>
			<form id="tracking-form">
				<input type="text" id="mc-tracking-number" name="order-number" placeholder="Enter your order number, e.g. 13126" value="" autofocus />
				<div>&nbsp;</div>
				<button id="mc-track-button" type="submit">Track</button>
			</form>

			<!-- Loader -->
			<div class="mc-hidden text-center" id="mc-tracking-loader">
				<div class="sk-circle">
					<div class="sk-circle1 sk-child">&nbsp;</div>
					<div class="sk-circle2 sk-child">&nbsp;</div>
					<div class="sk-circle3 sk-child">&nbsp;</div>
					<div class="sk-circle4 sk-child">&nbsp;</div>
					<div class="sk-circle5 sk-child">&nbsp;</div>
					<div class="sk-circle6 sk-child">&nbsp;</div>
					<div class="sk-circle7 sk-child">&nbsp;</div>
					<div class="sk-circle8 sk-child">&nbsp;</div>
					<div class="sk-circle9 sk-child">&nbsp;</div>
					<div class="sk-circle10 sk-child">&nbsp;</div>
					<div class="sk-circle11 sk-child">&nbsp;</div>
					<div class="sk-circle12 sk-child">&nbsp;</div>
				</div>
			</div>
		</div>

		<!-- Error message container -->
		<div id="mc-error-container" class="mc-hidden">
			<script id="mc-error-template" type="text/mustache">
				<span class="mc-error">{{error}}</span>
			</script>
		</div>
	</div>

	<!-- Tracking data container -->
	<div id="mc-tracking-container" class="mc-hidden">
		<!-- Mustache template for displaying tracking information -->
		<script id="mc-tracking-template" type="text/mustache">
			{{#shipments}}
			<div class="bobgo-tracking bobgo-tracking-shipment bobgo-card">
				<!-- Shipment Header -->
				<div class="bobgo-card">
					<div class="steps-header">
						<div class="section-heading">
							<div class="section-heading-header">{{shipment_tracking_reference}}</div>
						</div>
					</div>

					<!-- Movement Events -->
					<div class="steps-events">
						<div class="horizontal-line" style="margin-left: calc(50% / {{columns}}); width: calc(100% - 100% / {{columns}});"></div>
						<div class="steps-grid" style="grid-template-columns: repeat({{columns}}, minmax(0, 1fr));">
							{{#movementEvents}}
							<div class="step-container">
								<div class="step-image-container">
									<div class="image-flex-container line-container">
										<div class="image-container">
											<img src="{{image}}" alt="{{status}}" style="{{imageStyle}}" />
										</div>
										{{#shouldAddVerticalLine}}
										<div class="vertical-line"></div>
										{{/shouldAddVerticalLine}}
									</div>
								</div>
								<div class="step-text-container">
									<div class="text-flex-container">
										<div class="step-text-container-status">{{status}}</div>
										{{#hasDateAndTime}}
										<div class="step-text-container-time">{{date}}<br />{{time}}</div>
										{{/hasDateAndTime}}
									</div>
								</div>
							</div>
							{{/movementEvents}}
						</div>
					</div>
				</div>

				<!-- Shipping Details -->
				<div class="bobgo-card" style="margin-top: 30px;">
					<div class="shipping-details-header">
						<div class="section-heading">
							<div class="section-heading-header">Shipping details</div>
						</div>
						<div class="status-badge">{{status_friendly}}</div>
					</div>
					<div class="shipping-details">
						<div class="shipping-details-column">
							<div class="shipping-details-row">
								<div class="label-value-container">
									<div class="bobgo-label">Shipment</div>
									<div class="bobgo-value">{{shipment_tracking_reference}}</div>
								</div>
							</div>
							<div class="shipping-details-row">
								<div class="label-value-container">
									<div class="bobgo-label">Order</div>
									<div class="bobgo-value">{{order_number}}</div>
								</div>
							</div>
						</div>
						<div class="shipping-details-column">
							<div class="shipping-details-row">
								<div class="label-value-container">
									<div class="bobgo-label">Service level</div>
									<div class="bobgo-value">{{service_level}}</div>
								</div>
							</div>
							<div class="shipping-details-row">
								<div class="label-value-container">
									<div class="bobgo-label">Courier</div>
									<div class="bobgo-value">{{courier_name}}</div>
								</div>
							</div>
							{{#showEstimatedDeliveryRange}}
								<div class="shipping-details-row">
									<div class="label-value-container">
										<div class="bobgo-label">Estimated delivery</div>
										<div class="bobgo-value">{{deliveryRange}}</div>
									</div>
								</div>
							{{/showEstimatedDeliveryRange}}
						</div>
					</div>
				</div>

				<!-- Pickup Point Details -->
				{{#hasPickupPointDetails}}
				<div class="bobgo-card" style="margin-top: 30px;">
					<div class="section-heading" style="margin-bottom: 30px;">
						<div class="section-heading-header">{{pickupPointHeader}}</div>
					</div>
					<div class="location-details">
						<div class="label-value-container">
							<div class="bobgo-label">{{locationType}} name</div>
							<div class="bobgo-value">{{delivery_location.name}}</div>
						</div>
						<div class="label-value-container">
							<div class="bobgo-label">Location</div>
							<div class="location-wrapper">
								<div class="bobgo-value">{{delivery_location.address}}</div>
								{{#hasLocationDescription}}
								<div class="bobgo-value location-description">{{delivery_location.description}}</div>
								{{/hasLocationDescription}}
								<div class="bobgo-value">
									<span class="directions-link" id="{{shipment_tracking_reference}}" direction-lat="{{delivery_location.lat}}" direction-lng="{{delivery_location.lng}}">
											<svg class="directions-link-icon" aria-hidden="true" focusable="false" data-prefix="fas" data-icon="diamond-turn-right" role="img" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
											<path fill="currentColor" d="M227.7 11.7c15.6-15.6 40.9-15.6 56.6 0l216 216c15.6 15.6 15.6 40.9 0 56.6l-216 216c-15.6 15.6-40.9 15.6-56.6 0l-216-216c-15.6-15.6-15.6-40.9 0-56.6l216-216zm87.6 137c-4.6-4.6-11.5-5.9-17.4-3.5s-9.9 8.3-9.9 14.8v56H224c-35.3 0-64 28.7-64 64v48c0 13.3 10.7 24 24 24s24-10.7 24-24V280c0-8.8 7.2-16 16-16h64v56c0 6.5 3.9 12.3 9.9 14.8s12.9 1.1 17.4-3.5l80-80c6.2-6.2 6.2-16.4 0-22.6l-80-80z"></path>
											</svg>
											<span class="directions-link-text">Get directions</span>
									</span>
								</div>
							</div>
						</div>
						{{#hasInstructions}}
						<div class="location-instructions-wrapper">
							<div class="label-value-container">
								<div class="bobgo-label">Instructions</div>
							</div>
							<div class="location-instructions-wrapper">
								<ol class="location-instructions-list">
									{{#pickupPointInstructions}}
									<li>{{.}}</li>
									{{/pickupPointInstructions}}
								</ol>
							</div>
							<div class="location-support-container">
								<div>View our <a class="location-support-link support" href="https://www.bobbox.co.za/how-it-works/" target="_blank">frequently asked questions</a></div>
								<div>Contact our support team at <a class="location-support-link" href="mailto:support@bobbox.co.za">support@bobbox.co.za</a> or <a href="tel:+27129401063" class="location-support-link">(+27) 12 940 1063</a></div>
							</div>
						</div>
						{{/hasInstructions}}
					</div>
					{{#hasLocationImage}}
					<div class="location-image">
						<img src="{{delivery_location.image_url}}" alt="Bob Box location image" />
					</div>
					{{/hasLocationImage}}
				</div>
				{{/hasPickupPointDetails}}

				<!-- Tracking Events -->
				<div class="bobgo-card" style="margin-top: 30px;">
					<div class="section-heading" style="margin-bottom: 30px;">
						<div class="section-heading-header">Tracking events</div>
					</div>
					{{#isCancelled}}
					<div class="courier-info">The shipment has been cancelled.</div>
					{{/isCancelled}}
					{{#isPendingCollection}}
					<div class="courier-info">Your shipment will be collected soon. Please check back later for more information.</div>
					{{/isPendingCollection}}
					{{#hasTrackingEvents}}
					<!-- Loop through each grouped checkpoint (which represents a date) -->
					{{#groupedCheckpoints}}
					<div class="grouped-checkpoints">
						<div class="{{className}}" style="left:4.5px;"></div>
						<div style="z-index: 10;">
							<span class="location-marker"></span>
						</div>
						<!-- Display events for this date -->
						<div class="grouped-checkpoints-wrapper">
							<div class="checkpoints-header">{{date}}</div>
							{{#checkpoints}}
							<div class="checkpoint-wrapper">
								<div class="checkpoint-row">
									<div class="checkpoint-time">{{time_string}}</div>
									<div class="checkpoint-status-wrapper">
										<div class="checkpoint-status">{{status_friendly}}</div>
										<div class="checkpoint-message">{{message}}</div>
									</div>
								</div>
							</div>
							{{/checkpoints}}
						</div>
					</div>
					{{/groupedCheckpoints}}
					{{/hasTrackingEvents}}
					{{#courier_name}}
					{{#courier_phone}}
					<div class="courier-info">
						For additional information, please contact <strong>{{courier_name}} ({{courier_phone}})</strong> and quote tracking reference <strong>{{id}}</strong>.
					</div>
					{{/courier_phone}}
					{{/courier_name}}
				</div>
			</div>
			{{/shipments}}

			<!-- Branding -->
			{{#show_branding}}
			<div class="bobgo-logo">
				<a href="https://www.bobgo.co.za/" target="_blank">
					<img src="{{bobgo_logo}}" alt="Bob Go" title="Bob Go" />
				</a>
			</div>
			{{/show_branding}}
		</script>
	</div>
</div>
