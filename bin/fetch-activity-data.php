<?php

require_once dirname( __DIR__ ) . '/theme/functions.php';

/**
 * Fetches GitHub activity data (new/closed issues and new/merged PRs).
 *
 * @when before_wp_load
 */
function wp_cli_dashboard_fetch_activity_data( $args, $assoc_args ) {

	$config_file = WP_CLI_DASHBOARD_BASE_DIR . '/config.yml';
	if ( ! file_exists( $config_file ) ) {
		WP_CLI::error( 'Unable to load ./config.yml' );
	}

	$config = Spyc::YAMLLoad( $config_file );

	if ( ! getenv( 'GITHUB_TOKEN' ) ) {
		WP_CLI::error( 'GITHUB_TOKEN environment variable must be set.' );
	}

	$headers = array(
		'Accept'        => 'application/vnd.github.v3+json',
		'User-Agent'    => 'WP-CLI',
		'Authorization' => 'token ' . getenv( 'GITHUB_TOKEN' ),
	);

	$activity_dir = WP_CLI_DASHBOARD_BASE_DIR . '/github-data/activity';
	if ( ! is_dir( $activity_dir ) ) {
		mkdir( $activity_dir, 0777, true );
	}

	$new_issues    = array();
	$closed_issues = array();
	$new_prs       = array();
	$merged_prs    = array();
	$since_date    = date( 'Y-m-d\TH:i:s\Z', strtotime( '1 year ago' ) );

	WP_CLI::log( sprintf( 'Fetching activity data for %d repositories...', count( $config['github_repositories'] ) ) );

	foreach ( $config['github_repositories'] as $repo ) {
		WP_CLI::log( sprintf( ' - %s', $repo ) );

		// 1. Fetch Issues (New and Closed)
		$page = 1;
		do {
			$query = array(
				'state'    => 'all',
				'since'    => $since_date,
				'per_page' => 100,
				'page'     => $page,
			);
			$url = sprintf( 'https://api.github.com/repos/%s/issues', $repo );
			
			$response = WP_CLI\Utils\http_request( 'GET', $url, $query, $headers, array( 'timeout' => 30 ) );
			
			if ( 20 !== (int) substr( $response->status_code, 0, 2 ) ) {
				WP_CLI::warning( sprintf( 'Failed to fetch issues for %s: %s', $repo, $response->body ) );
				break;
			}

			$items = json_decode( $response->body );
			if ( empty( $items ) ) {
				break;
			}

			foreach ( $items as $item ) {
				// Skip PRs (which are returned in the issues endpoint)
				if ( isset( $item->pull_request ) ) {
					continue;
				}
				
				if ( $item->created_at > $since_date ) {
					$new_issues[] = $item->created_at;
				}

				if ( ! empty( $item->closed_at ) && $item->closed_at > $since_date ) {
					$closed_issues[] = $item->closed_at;
				}
			}

			$page++;
			if ( $page > 50 ) break;

		} while ( count( $items ) === 100 );

		// 2. Fetch PRs (New and Merged)
		$page = 1;
		do {
			$query = array(
				'state'     => 'all',
				'sort'      => 'updated',
				'direction' => 'desc',
				'per_page'  => 100,
				'page'      => $page,
			);
			$url = sprintf( 'https://api.github.com/repos/%s/pulls', $repo );

			$response = WP_CLI\Utils\http_request( 'GET', $url, $query, $headers, array( 'timeout' => 30 ) );

			if ( 20 !== (int) substr( $response->status_code, 0, 2 ) ) {
				WP_CLI::warning( sprintf( 'Failed to fetch pulls for %s: %s', $repo, $response->body ) );
				break;
			}

			$items = json_decode( $response->body );
			if ( empty( $items ) ) {
				break;
			}

			$continue_paging = true;
			foreach ( $items as $item ) {
				// Check if we passed the date threshold
				if ( $item->updated_at < $since_date ) {
					$continue_paging = false;
					break; 
				}

				if ( $item->created_at > $since_date ) {
					$new_prs[] = $item->created_at;
				}

				if ( ! empty( $item->merged_at ) && $item->merged_at > $since_date ) {
					$merged_prs[] = $item->merged_at;
				}
			}

			if ( ! $continue_paging ) {
				break;
			}

			$page++;
			if ( $page > 50 ) break;

		} while ( count( $items ) === 100 );
	}

	file_put_contents( $activity_dir . '/new-issues.json', json_encode( $new_issues ) );
	file_put_contents( $activity_dir . '/closed-issues.json', json_encode( $closed_issues ) );
	file_put_contents( $activity_dir . '/new-prs.json', json_encode( $new_prs ) );
	file_put_contents( $activity_dir . '/merged-prs.json', json_encode( $merged_prs ) );

	WP_CLI::success( sprintf( 'Fetched activity: %d new issues, %d closed issues, %d new PRs, %d merged PRs.', count( $new_issues ), count( $closed_issues ), count( $new_prs ), count( $merged_prs ) ) );
}

WP_CLI::add_command( 'dashboard fetch-activity-data', 'wp_cli_dashboard_fetch_activity_data' );
