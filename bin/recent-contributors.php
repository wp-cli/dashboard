<?php

/**
 * Generates a list of recent contributors
 *
 * ## OPTIONS
 *
 * [--months=<months>]
 * : Number of months back to look.
 * ---
 * default: 6
 * ---
 *
 * [--format=<format>]
 * : Render output in a particular format.
 * ---
 * default: table
 * options:
 *  - table
 *  - csv
 *  - json
 * ---
 *
 * @when before_wp_load
 */
function wp_cli_dashboard_recent_contributors( $args, $assoc_args ) {

	$cutoff              = gmdate( 'Y-m-d', strtotime( sprintf( '-%d months', $assoc_args['months'] ) ) );
	$recent_contributors = array();
	foreach ( wp_cli_dashboard_contributors() as $login => $person ) {
		if ( end( $person['dates'] ) >= $cutoff ) {
			$recent_contributors[] = array(
				'contributor' => $login,
			);
		}
	}

	WP_CLI\Utils\format_items( $assoc_args['format'], $recent_contributors, array( 'contributor' ) );
}

WP_CLI::add_command( 'dashboard recent-contributors', 'wp_cli_dashboard_recent_contributors' );
