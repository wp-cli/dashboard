<?php
/**
 * Various functions for the dashboard.
 */

define( 'WP_CLI_DASHBOARD_BASE_DIR', dirname( __DIR__ ) );

/**
 * Get a rendered template part
 *
 * @param string $template
 * @param array $vars
 * @return string
 */
function wp_cli_dashboard_get_template_part( $template, $vars = array() ) {

	$full_path = WP_CLI_DASHBOARD_BASE_DIR . '/theme/' . $template . '.php';

	if ( ! file_exists( $full_path ) ) {
		return '';
	}

	ob_start();
	// @codingStandardsIgnoreStart
	if ( ! empty( $vars ) ) {
		extract( $vars );
	}
	// @codingStandardsIgnoreEnd
	include $full_path;
	return ob_get_clean();
}

/**
 * Gets config data
 *
 * @param string $key Config key
 * @return mixed
 */
function wp_cli_dashboard_get_config_data( $key ) {
	$config_file = WP_CLI_DASHBOARD_BASE_DIR . '/config.yml';
	if ( ! file_exists( $config_file ) ) {
		WP_CLI::error( 'Unable to load ./config.yml' );
	}

	$config = Spyc::YAMLLoad( $config_file );
	return isset( $config[ $key ] ) ? $config[ $key ] : null;
}
