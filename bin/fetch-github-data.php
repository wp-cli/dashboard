<?php

require_once dirname( __DIR__ ) . '/theme/functions.php';

/**
 * Fetches GitHub data defined in ./config.yml and stores it in in ./github-data.
 *
 * ## OPTIONS
 *
 * [--only-data]
 * : Only update the data points, not the repositories.
 *
 * [--force]
 * : Forcefully overwrite any existing data.
 *
 * @when before_wp_load
 */
function wp_cli_dashboard_fetch_github_data( $args, $assoc_args ) {

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

	$headers = array(
		'Accept'        => 'application/vnd.github.v3+json',
		'User-Agent'    => 'WP-CLI',
		'Authorization' => 'token ' . getenv( 'GITHUB_TOKEN' ),
	);

	WP_CLI::log( sprintf( 'Fetching %d GitHub data points...', count( $config['github_data'] ) ) );
	foreach ( $config['github_data'] as $key => $meta ) {

		if ( empty( $meta['search'] ) ) {
			WP_CLI::warning( sprintf( 'Invalid \'search\' for %s', $key ) );
			continue;
		}

		$time = date( 'Y-m-d-H-00' );
		$path = WP_CLI_DASHBOARD_BASE_DIR . '/github-data/' . $key . '/' . $time;
		if ( file_exists( $path ) && empty( $assoc_args['force'] ) ) {
			WP_CLI::log( sprintf( 'Skipping: Data already exists for %s on %s', $key, $time ) );
			continue;
		}

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

		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		file_put_contents( $path, $total_count );
		WP_CLI::log( sprintf( 'Saved: Total count for %s on %s: %d', $key, $time, $total_count ) );
	}

	if ( ! WP_CLI\Utils\get_flag_value( $assoc_args, 'only-data' ) ) {
		WP_CLI::log( sprintf( 'Fetching %d GitHub repository data...', count( $config['github_repositories'] ) ) );
		foreach ( $config['github_repositories'] as $repo ) {

			$repo_short = str_replace( 'wp-cli/', '', $repo );

			$path = WP_CLI_DASHBOARD_BASE_DIR . '/github-data/repositories/' . $repo_short . '.json';
			$repository_data = array(
				'open_issues'        => null,
				'open_pull_requests' => null,
				'active_milestone'   => null,
				'latest_release'     => null,
			);

			$response = WP_CLI\Utils\http_request( 'GET', sprintf( 'https://api.github.com/repos/%s', $repo ), array(), $headers );
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
			$repository_data['open_issues'] = $data->open_issues;

			$query = array(
				'per_page' => 100,
			);
			$response = WP_CLI\Utils\http_request( 'GET', sprintf( 'https://api.github.com/repos/%s/pulls', $repo ), $query, $headers );
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
			$repository_data['open_pull_requests'] = count( $data );
			$repository_data['open_issues'] = $repository_data['open_issues'] - $repository_data['open_pull_requests'];

			$response = WP_CLI\Utils\http_request( 'GET', sprintf( 'https://api.github.com/repos/%s/milestones', $repo ), array(), $headers );
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
			if ( ! empty( $data ) ) {
				$repository_data['active_milestone'] = array_shift( $data );
			}

			$response = WP_CLI\Utils\http_request( 'GET', sprintf( 'https://api.github.com/repos/%s/releases', $repo ), array(), $headers );
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
			if ( ! empty( $data ) ) {
				$repository_data['latest_release'] = array_shift( $data );
			}

			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0777, true );
			}
			file_put_contents( $path, json_encode( $repository_data ) );
			WP_CLI::log( sprintf( 'Saved: %s', $repo ) );
		}
	}

	WP_CLI::success( 'Fetch data complete.' );
}

WP_CLI::add_command( 'dashboard fetch-github-data', 'wp_cli_dashboard_fetch_github_data' );
