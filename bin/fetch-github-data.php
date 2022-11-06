<?php

/**
 * Fetches GitHub data defined in ./config.yml and stores it in in ./github-data.
 *
 * @when before_wp_load
 */
function wp_cli_dashboard_fetch_github_data() {

	define( 'WP_CLI_DASHBOARD_BASE_DIR', dirname( __DIR__ ) );

	$config_file = WP_CLI_DASHBOARD_BASE_DIR . '/config.yml';
	if ( ! file_exists( $config_file ) ) {
		WP_CLI::error( 'Unable to load ./config.yml' );
	}

	$config = Spyc::YAMLLoad( $config_file );
	if ( empty( $config['github_data'] ) ) {
		WP_CLI::error( 'No \'github_data\' attribute found in ./config.yml' );
	}

	if ( ! getenv( 'GITHUB_TOKEN' ) ) {
		WP_CLI::error( 'GITHUB_TOKEN environment variable must be set.' );
	}

	if ( ! is_dir( WP_CLI_DASHBOARD_BASE_DIR . '/data' ) ) {
		mkdir( WP_CLI_DASHBOARD_BASE_DIR . '/data' );
	}

	WP_CLI::log( sprintf( 'Fetching %d GitHub data points...', count( $config['github_data'] ) ) );
	foreach ( $config['github_data'] as $key => $meta ) {

		if ( empty( $meta['search'] ) ) {
			WP_CLI::warning( sprintf( 'Invalid \'search\' for %s', $key ) );
			continue;
		}

		$headers = array(
			'Accept'        => 'application/vnd.github.v3+json',
			'User-Agent'    => 'WP-CLI',
			'Authorization' => 'token ' . getenv( 'GITHUB_TOKEN' ),
		);
		$request_url = sprintf(
			'https://api.github.com/search/issues'
		);
		$query = array(
			'q' => $meta['search'],
		);
		$response = WP_CLI\Utils\http_request( 'GET', 'https://api.github.com/search/issues', $query, $headers );
		if ( 20 !== (int) substr( $response->status_code, 0, 2 ) ) {
			WP_CLI::error(
				sprintf(
					"Failed request. GitHub API returned: %s (HTTP code %d)",
					$response->body,
					$response->status_code
				)
			);
		}
		$data = json_decode( $response->body );
		$total_count = $data->total_count;

		WP_CLI::log( sprintf( 'Current count for %s: %d', $key, $total_count ) );
		$file = date( 'Y-m-d' );
		$path = WP_CLI_DASHBOARD_BASE_DIR . '/github-data/' . $key . '/' . $file;

		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		file_put_contents( $path, $total_count );
	}

	WP_CLI::success( 'Fetch data complete.' );
}

WP_CLI::add_command( 'dashboard fetch-github-data', 'wp_cli_dashboard_fetch_github_data' );
