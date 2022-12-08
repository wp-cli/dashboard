<?php

require_once dirname( __DIR__ ) . '/theme/functions.php';

/**
 * Builds the WP-CLI committer dashboard.
 *
 * @when before_wp_load
 */
function wp_cli_dashboard_build_dashboard( $args, $assoc_args ) {

	$html = wp_cli_dashboard_get_template_part( 'index' );

	file_put_contents( WP_CLI_DASHBOARD_BASE_DIR . '/index.html', trim( $html ) );
	WP_CLI::success( 'Dashboard built.' );
}

WP_CLI::add_command( 'dashboard build', 'wp_cli_dashboard_build_dashboard' );
