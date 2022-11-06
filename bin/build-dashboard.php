<?php

/**
 * Builds the WP-CLI committer dashboard.
 *
 * @when before_wp_load
 */
function wp_cli_dashboard_build_dashboard( $args, $assoc_args ) {

	define( 'WP_CLI_DASHBOARD_BASE_DIR', dirname( __DIR__ ) );


	$html = <<<EOT
<!DOCTYPE html>
<html>
<head>
<title>WP-CLI Committer Dashboard</title>
</head>

<body>
EOT;

	$html .= '</body></html>';

	file_put_contents( WP_CLI_DASHBOARD_BASE_DIR . '/index.html', $html );
	WP_CLI::success( 'Dashboard built.' ); 
}

WP_CLI::add_command( 'dashboard build', 'wp_cli_dashboard_build_dashboard' );
