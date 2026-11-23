<?php

require_once dirname( __DIR__ ) . '/theme/functions.php';

/**
 * Converts data stored in the old one-file-per-reading layout.
 *
 * - github-data/<metric>/<Y-m-d-H-i> files become github-data/metrics/<metric>.json,
 *   keeping the last reading of each day.
 * - github-data/contributors/<login> files become the "legacy" events in
 *   github-data/contributors.json (bots are dropped).
 * - github-data/contributor-details is removed; avatars are loaded from
 *   github.com/<login>.png instead.
 *
 * Runs automatically before every fetch, so it's safe to call repeatedly.
 *
 * @when before_wp_load
 */
function wp_cli_dashboard_migrate_data_command() {
	wp_cli_dashboard_migrate_legacy_data();
	WP_CLI::success( 'Data migrated.' );
}

/**
 * Migrates legacy data, if any is present.
 */
function wp_cli_dashboard_migrate_legacy_data() {
	$dir = WP_CLI_DASHBOARD_DATA_DIR;

	foreach ( glob( $dir . '/*', GLOB_ONLYDIR ) as $metric_dir ) {
		$key   = basename( $metric_dir );
		$files = glob( $metric_dir . '/[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]-[0-9][0-9]-[0-9][0-9]' );
		if ( empty( $files ) ) {
			continue;
		}
		sort( $files );
		$series = wp_cli_dashboard_read_json( "metrics/{$key}.json" );
		$legacy = array();
		foreach ( $files as $file ) {
			$legacy[ substr( basename( $file ), 0, 10 ) ] = (int) trim( file_get_contents( $file ) );
		}
		// Readings already in the new format are newer; keep them.
		$series = array_merge( $legacy, $series );
		ksort( $series );
		wp_cli_dashboard_write_json( "metrics/{$key}.json", $series );
		wp_cli_dashboard_remove_dir( $metric_dir );
		WP_CLI::log( sprintf( 'Migrated %d readings for %s.', count( $files ), $key ) );
	}

	if ( is_dir( $dir . '/contributors' ) ) {
		$events = array();
		foreach ( glob( $dir . '/contributors/*' ) as $file ) {
			$login = basename( $file );
			foreach ( explode( PHP_EOL, file_get_contents( $file ) ) as $date ) {
				$date = trim( $date );
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
					$events[] = array( $login, 'legacy', $date );
				}
			}
		}
		if ( ! function_exists( 'wp_cli_dashboard_add_contributor_events' ) ) {
			require_once __DIR__ . '/fetch-activity-data.php';
		}
		wp_cli_dashboard_add_contributor_events( $events );
		wp_cli_dashboard_remove_dir( $dir . '/contributors' );
		WP_CLI::log( sprintf( 'Migrated %d contributor events.', count( $events ) ) );
	}

	if ( is_dir( $dir . '/contributor-details' ) ) {
		wp_cli_dashboard_remove_dir( $dir . '/contributor-details' );
	}
}

/**
 * Recursively removes a directory.
 *
 * @param string $path
 */
function wp_cli_dashboard_remove_dir( $path ) {
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $file ) {
		$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
	}
	rmdir( $path );
}

WP_CLI::add_command( 'dashboard migrate-data', 'wp_cli_dashboard_migrate_data_command' );
