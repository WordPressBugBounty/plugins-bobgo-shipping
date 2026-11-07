<?php
/**
 * Elementor widget for the Bob Go tracking shortcode.
 *
 * @package BobGo_Shipping
 */

namespace BobGo_Shipping\app;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

/**
 * Elementor widget that renders the Bob Go tracking shortcode output.
 */
class ElementorWidget extends \Elementor\Widget_Base {

	/**
	 * Get the widget name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Bob Go Tracking';
	}

	/**
	 * Get the widget title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return 'Bob Go Tracking';
	}

	/**
	 * Get the widget icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-progress-tracker';
	}

	/**
	 * Get the widget categories.
	 *
	 * @return string[]
	 */
	public function get_categories(): array {
		return [ 'bobgo' ];
	}

	/**
	 * Render the widget output.
	 *
	 * @return void
	 */
	public function render() {
		echo do_shortcode( '[bobgo]' );
	}
}
