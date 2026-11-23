<?php

require_once dirname( __DIR__ ) . '/theme/functions.php';

/**
 * Fetches GitHub data defined in ./config.yml and stores it in ./github-data.
 *
 * Contributor data is derived from the activity data; see
 * `wp dashboard fetch-activity-data`.
 *
 * ## OPTIONS
 *
 * [--only=<section>]
 * : Only rebuild a specific category of data.
 * ---
 * options:
 *   - data
 *   - repositories
 * ---
 *
 * @when before_wp_load
 */
function wp_cli_dashboard_fetch_github_data( $args, $assoc_args ) {

	wp_cli_dashboard_migrate_legacy_data();

	$github_data = wp_cli_dashboard_get_config_data( 'github_data' );
	if ( empty( $github_data ) ) {
		WP_CLI::error( 'No \'github_data\' attribute found in ./config.yml' );
	}
	wp_cli_dashboard_github_headers(); // Bail early without a token.

	if ( empty( $assoc_args['only'] ) || 'data' === $assoc_args['only'] ) {
		wp_cli_dashboard_fetch_metrics( $github_data );
	}

	if ( empty( $assoc_args['only'] ) || 'repositories' === $assoc_args['only'] ) {
		wp_cli_dashboard_fetch_repositories( wp_cli_dashboard_get_config_data( 'github_repositories' ) );
	}

	WP_CLI::success( 'Fetch data complete.' );
}

/**
 * Records today's value for each search-based metric.
 *
 * Values are stored per day in ./github-data/metrics/<key>.json; the last
 * reading of a day wins.
 *
 * @param array $github_data
 */
function wp_cli_dashboard_fetch_metrics( $github_data ) {
	WP_CLI::log( sprintf( 'Fetching %d GitHub data points...', count( $github_data ) ) );
	$today = gmdate( 'Y-m-d' );
	foreach ( $github_data as $key => $meta ) {
		if ( empty( $meta['search'] ) ) {
			WP_CLI::warning( sprintf( 'Invalid \'search\' for %s', $key ) );
			continue;
		}
		$data = wp_cli_dashboard_github_get( 'https://api.github.com/search/issues', array( 'q' => $meta['search'], 'per_page' => 1 ) );
		if ( ! isset( $data['total_count'] ) ) {
			continue; // Keep a gap rather than record a bogus value.
		}
		$series           = wp_cli_dashboard_read_json( "metrics/{$key}.json" );
		$series[ $today ] = (int) $data['total_count'];
		ksort( $series );
		wp_cli_dashboard_write_json( "metrics/{$key}.json", $series );
		WP_CLI::log( sprintf( 'Saved: %s on %s: %d', $key, $today, $data['total_count'] ) );
	}
}

/**
 * Fetches per-repository status: counts, milestone, release, unreleased
 * commits and CI state.
 *
 * @param array $repositories List of owner/name.
 */
function wp_cli_dashboard_fetch_repositories( $repositories ) {
	WP_CLI::log( sprintf( 'Fetching %d GitHub repository data...', count( $repositories ) ) );

	// One GraphQL request covers the counts for every repository.
	$fragments = array();
	foreach ( array_values( $repositories ) as $i => $repo ) {
		list( $owner, $name ) = explode( '/', $repo );
		$fragments[]          = sprintf(
			'r%d: repository(owner: %s, name: %s) {
				nameWithOwner
				pushedAt
				isArchived
				issues(states: OPEN) { totalCount }
				pullRequests(states: OPEN) { totalCount }
				milestones(states: OPEN, first: 1, orderBy: {field: DUE_DATE, direction: ASC}) {
					nodes {
						title
						url
						dueOn
						openIssues: issues(states: OPEN) { totalCount }
						closedIssues: issues(states: CLOSED) { totalCount }
						openPRs: pullRequests(states: OPEN) { totalCount }
						closedPRs: pullRequests(states: [CLOSED, MERGED]) { totalCount }
					}
				}
				latestRelease { tagName url publishedAt }
				defaultBranchRef { name }
			}',
			$i,
			json_encode( $owner ),
			json_encode( $name )
		);
	}
	$data = wp_cli_dashboard_graphql( 'query { ' . implode( "\n", $fragments ) . ' }' );
	if ( null === $data ) {
		WP_CLI::warning( 'Unable to fetch repository data; keeping previous values.' );
		return;
	}

	foreach ( array_values( $repositories ) as $i => $repo ) {
		$node = isset( $data[ "r{$i}" ] ) ? $data[ "r{$i}" ] : null;
		if ( empty( $node ) ) {
			WP_CLI::warning( sprintf( 'No data returned for %s; keeping previous values.', $repo ) );
			continue;
		}
		$repo_short = str_replace( 'wp-cli/', '', $repo );
		$branch     = isset( $node['defaultBranchRef']['name'] ) ? $node['defaultBranchRef']['name'] : 'main';

		$milestone = null;
		if ( ! empty( $node['milestones']['nodes'][0] ) ) {
			$m         = $node['milestones']['nodes'][0];
			$milestone = array(
				'title'  => $m['title'],
				'url'    => $m['url'],
				'due_on' => $m['dueOn'],
				'open'   => $m['openIssues']['totalCount'] + $m['openPRs']['totalCount'],
				'closed' => $m['closedIssues']['totalCount'] + $m['closedPRs']['totalCount'],
			);
		}

		$release = null;
		if ( ! empty( $node['latestRelease'] ) ) {
			$release = array(
				'tag'          => $node['latestRelease']['tagName'],
				'url'          => $node['latestRelease']['url'],
				'published_at' => $node['latestRelease']['publishedAt'],
			);
		}

		$repository_data = array(
			'schema'             => 2,
			'default_branch'     => $branch,
			'archived'           => (bool) $node['isArchived'],
			'pushed_at'          => $node['pushedAt'],
			'open_issues'        => $node['issues']['totalCount'],
			'open_pull_requests' => $node['pullRequests']['totalCount'],
			'milestone'          => $milestone,
			'release'            => $release,
			'unreleased'         => $release ? wp_cli_dashboard_fetch_unreleased( $repo, $release['tag'], $branch ) : null,
			'ci'                 => array(
				'testing'      => wp_cli_dashboard_fetch_workflow_status( $repo, 'testing.yml', $branch ),
				'code_quality' => wp_cli_dashboard_fetch_workflow_status( $repo, 'code-quality.yml', $branch ),
			),
		);

		wp_cli_dashboard_write_json( "repositories/{$repo_short}.json", $repository_data );
		WP_CLI::log( sprintf( 'Saved: %s', $repo ) );
	}
}

/**
 * Counts commits on the default branch that aren't in the latest release.
 *
 * @param string $repo
 * @param string $tag
 * @param string $branch
 * @return array|null { total: int, human: int, url: string }
 */
function wp_cli_dashboard_fetch_unreleased( $repo, $tag, $branch ) {
	$url     = sprintf( 'https://api.github.com/repos/%s/compare/%s...%s', $repo, rawurlencode( $tag ), rawurlencode( $branch ) );
	$compare = wp_cli_dashboard_github_get( $url );
	if ( ! isset( $compare['ahead_by'] ) ) {
		return null;
	}
	// The compare API lists at most 250 commits; that's plenty to tell
	// "needs a release" apart from "only dependency bumps".
	$human = 0;
	foreach ( isset( $compare['commits'] ) ? $compare['commits'] : array() as $commit ) {
		$login = isset( $commit['author']['login'] ) ? $commit['author']['login'] : '';
		$type  = isset( $commit['author']['type'] ) ? $commit['author']['type'] : null;
		if ( '' === $login && isset( $commit['commit']['author']['name'] ) ) {
			$login = $commit['commit']['author']['name'];
		}
		if ( ! wp_cli_dashboard_is_bot( $login, $type ) ) {
			$human++;
		}
	}
	return array(
		'total' => (int) $compare['ahead_by'],
		'human' => $human,
		'url'   => sprintf( 'https://github.com/%s/compare/%s...%s', $repo, rawurlencode( $tag ), rawurlencode( $branch ) ),
	);
}

/**
 * Gets the conclusion of the latest completed run of a workflow on a branch.
 *
 * @param string $repo
 * @param string $workflow Workflow file name.
 * @param string $branch
 * @return array|null { conclusion: string, url: string, at: string }, or null when the workflow doesn't exist.
 */
function wp_cli_dashboard_fetch_workflow_status( $repo, $workflow, $branch ) {
	$url      = sprintf( 'https://api.github.com/repos/%s/actions/workflows/%s/runs', $repo, $workflow );
	$response = wp_cli_dashboard_github_request(
		'GET',
		$url,
		array(
			'branch'                => $branch,
			'status'                => 'completed',
			'per_page'              => 1,
			'exclude_pull_requests' => 'true',
		),
		true
	);
	if ( null === $response ) {
		return null;
	}
	$runs = json_decode( $response->body, true );
	if ( empty( $runs['workflow_runs'][0] ) ) {
		return null;
	}
	$run = $runs['workflow_runs'][0];
	return array(
		'conclusion' => $run['conclusion'],
		'url'        => $run['html_url'],
		'at'         => $run['updated_at'],
	);
}

WP_CLI::add_command( 'dashboard fetch-github-data', 'wp_cli_dashboard_fetch_github_data' );
