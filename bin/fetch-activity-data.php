<?php

require_once dirname( __DIR__ ) . '/theme/functions.php';

/**
 * Fetches issue and pull request activity and derives contributor data.
 *
 * Issues and pull requests are cached in ./github-data/activity/items.json.
 * The first run (or a run with --backfill) fetches everything updated in the
 * past 12 months plus all open items; later runs only fetch items updated
 * since the previous run.
 *
 * Contributor events (opened/merged PRs, issues, comments, reviews) are
 * accumulated in ./github-data/contributors.json.
 *
 * ## OPTIONS
 *
 * [--backfill]
 * : Refetch the full 12-month window instead of only recent updates.
 *
 * @when before_wp_load
 */
function wp_cli_dashboard_fetch_activity_data( $args, $assoc_args ) {

	wp_cli_dashboard_migrate_legacy_data();
	wp_cli_dashboard_github_headers(); // Bail early without a token.

	$repositories = wp_cli_dashboard_get_config_data( 'github_repositories' );
	$meta         = wp_cli_dashboard_read_json( 'activity/meta.json' );
	$items        = wp_cli_dashboard_read_json( 'activity/items.json' );
	$now          = time();
	$window_start = strtotime( '-12 months -7 days', $now );
	$started_at   = gmdate( 'Y-m-d\TH:i:s\Z', $now );

	$full = ! empty( $assoc_args['backfill'] )
		|| empty( $meta['last_fetch'] )
		|| empty( $items )
		|| strtotime( $meta['last_fetch'] ) < strtotime( '-30 days', $now );

	// GitHub's issue search returns nothing unless the query names a type,
	// so issues and pull requests are searched separately.
	$bases = array( 'org:wp-cli is:issue', 'org:wp-cli is:pr' );
	$nodes = array();
	$ok    = true;
	if ( $full ) {
		WP_CLI::log( 'Backfilling 12 months of activity...' );
		foreach ( $bases as $base ) {
			// Month-sized slices keep each search below GitHub's 1,000 result
			// cap; larger slices are split further automatically.
			for ( $from = $window_start; $from < $now; $from = $to ) {
				$to = min( strtotime( '+1 month', $from ), $now + HOUR_IN_SECONDS );
				$ok = wp_cli_dashboard_search_range( $base, 'updated', $from, $to, $nodes ) && $ok;
			}
			// Items still open but untouched for over a year (needed for stale PRs).
			$ok = wp_cli_dashboard_search_all( sprintf( '%s is:open updated:<%s', $base, gmdate( 'Y-m-d\TH:i:s\Z', $window_start ) ), $nodes ) && $ok;
		}
		if ( empty( $nodes ) ) {
			// A year of wp-cli activity is never empty; the query is broken.
			WP_CLI::warning( 'Backfill returned no issues or pull requests; keeping previous data.' );
			$ok = false;
		}
		if ( $ok ) {
			$items = array(); // Start clean so deleted/transferred items drop out.
		}
	} else {
		$since = strtotime( $meta['last_fetch'] ) - HOUR_IN_SECONDS;
		WP_CLI::log( sprintf( 'Fetching activity updated since %s...', gmdate( 'Y-m-d H:i', $since ) ) );
		foreach ( $bases as $base ) {
			$ok = wp_cli_dashboard_search_range( $base, 'updated', $since, $now + HOUR_IN_SECONDS, $nodes ) && $ok;
		}
	}

	$count = 0;
	foreach ( $nodes as $node ) {
		$item = wp_cli_dashboard_normalize_activity_node( $node );
		if ( null === $item || ! in_array( $item['repo'], $repositories, true ) ) {
			continue;
		}
		$items[ $item['repo'] . '#' . $item['number'] ] = $item;
		$count++;
	}

	// Drop items that no longer touch the window.
	$cutoff = gmdate( 'Y-m-d', $window_start );
	foreach ( $items as $key => $item ) {
		$relevant = 'open' === $item['state']
			|| $item['created_at'] >= $cutoff
			|| ( ! empty( $item['closed_at'] ) && $item['closed_at'] >= $cutoff );
		if ( ! $relevant ) {
			unset( $items[ $key ] );
		}
	}
	ksort( $items, SORT_NATURAL );

	wp_cli_dashboard_write_json( 'activity/items.json', $items );
	wp_cli_dashboard_update_contributors( $items );

	if ( empty( $meta['history_backfilled'] ) ) {
		$meta['history_backfilled'] = wp_cli_dashboard_backfill_pr_history( $window_start );
	}

	if ( $ok ) {
		// Only advance the cursor when every request succeeded, so a failed
		// run is retried from the same point next hour.
		$meta['last_fetch'] = $started_at;
		if ( $full ) {
			$meta['last_backfill'] = $started_at;
			// Replaced by items.json.
			foreach ( array( 'new-issues', 'closed-issues', 'new-prs', 'merged-prs' ) as $legacy ) {
				@unlink( WP_CLI_DASHBOARD_DATA_DIR . "/activity/{$legacy}.json" );
			}
		}
	} else {
		WP_CLI::warning( 'Some activity requests failed; they will be retried on the next run.' );
	}
	wp_cli_dashboard_write_json( 'activity/meta.json', $meta );

	WP_CLI::success( sprintf( 'Fetched %d updated items; %d items cached.', $count, count( $items ) ) );
}

/**
 * GraphQL fields fetched for each issue / pull request.
 */
function wp_cli_dashboard_activity_fields() {
	$actor = 'author { login __typename }';
	return "
		__typename
		... on Issue {
			number title url state createdAt updatedAt closedAt
			repository { nameWithOwner }
			{$actor}
			comments(first: 20) { nodes { createdAt {$actor} } }
		}
		... on PullRequest {
			number title url state isDraft createdAt updatedAt closedAt mergedAt
			repository { nameWithOwner }
			{$actor}
			mergedBy { login __typename }
			comments(first: 20) { nodes { createdAt {$actor} } }
			reviews(first: 20) { nodes { submittedAt {$actor} } }
		}
	";
}

/**
 * Searches a time range, splitting it whenever it holds more results than
 * the search API returns (1,000).
 *
 * @param string $base   Base search query.
 * @param string $field  Date qualifier (created, updated, ...).
 * @param int    $from
 * @param int    $to
 * @param array  $nodes  Collected nodes (by reference).
 * @param string $fields GraphQL fields per node.
 * @return bool Whether all requests succeeded.
 */
function wp_cli_dashboard_search_range( $base, $field, $from, $to, &$nodes, $fields = null ) {
	$query = sprintf( '%s %s:%s..%s', $base, $field, gmdate( 'Y-m-d\TH:i:s\Z', $from ), gmdate( 'Y-m-d\TH:i:s\Z', $to ) );
	$page  = wp_cli_dashboard_search_page( $query, null, $fields );
	if ( null === $page ) {
		return false;
	}
	if ( $page['issueCount'] > 1000 && ( $to - $from ) > HOUR_IN_SECONDS ) {
		$mid = (int) ( ( $from + $to ) / 2 );
		$a   = wp_cli_dashboard_search_range( $base, $field, $from, $mid, $nodes, $fields );
		$b   = wp_cli_dashboard_search_range( $base, $field, $mid, $to, $nodes, $fields );
		return $a && $b;
	}
	return wp_cli_dashboard_search_all( $query, $nodes, $fields, $page );
}

/**
 * Collects every page of a search.
 *
 * @param string     $query
 * @param array      $nodes  Collected nodes (by reference).
 * @param string     $fields GraphQL fields per node.
 * @param array|null $page   Already-fetched first page.
 * @return bool Whether all requests succeeded.
 */
function wp_cli_dashboard_search_all( $query, &$nodes, $fields = null, $page = null ) {
	if ( null === $page ) {
		$page = wp_cli_dashboard_search_page( $query, null, $fields );
	}
	while ( null !== $page ) {
		foreach ( $page['nodes'] as $node ) {
			if ( ! empty( $node ) ) {
				$nodes[] = $node;
			}
		}
		if ( empty( $page['pageInfo']['hasNextPage'] ) ) {
			return true;
		}
		$page = wp_cli_dashboard_search_page( $query, $page['pageInfo']['endCursor'], $fields );
	}
	return false;
}

/**
 * Fetches one page of search results.
 *
 * @param string      $query
 * @param string|null $cursor
 * @param string|null $fields
 * @return array|null The `search` member.
 */
function wp_cli_dashboard_search_page( $query, $cursor = null, $fields = null ) {
	$fields = $fields ? $fields : wp_cli_dashboard_activity_fields();
	$data   = wp_cli_dashboard_graphql(
		'query($q: String!, $cursor: String) {
			search(query: $q, type: ISSUE, first: 50, after: $cursor) {
				issueCount
				pageInfo { hasNextPage endCursor }
				nodes { ' . $fields . ' }
			}
		}',
		array(
			'q'      => $query,
			'cursor' => $cursor,
		)
	);
	return isset( $data['search'] ) ? $data['search'] : null;
}

/**
 * Converts a GraphQL search node into the cached item format.
 *
 * @param array $node
 * @return array|null
 */
function wp_cli_dashboard_normalize_activity_node( $node ) {
	if ( empty( $node['__typename'] ) || ! in_array( $node['__typename'], array( 'Issue', 'PullRequest' ), true ) ) {
		return null;
	}
	$is_pr  = 'PullRequest' === $node['__typename'];
	$author = isset( $node['author']['login'] ) ? $node['author']['login'] : '';
	$a_type = isset( $node['author']['__typename'] ) ? $node['author']['__typename'] : null;

	// Everyone who commented or reviewed, and the earliest response from
	// someone other than the author.
	$participants   = array();
	$first_response = null;
	$responses      = array();
	foreach ( isset( $node['comments']['nodes'] ) ? $node['comments']['nodes'] : array() as $c ) {
		$responses[] = array( isset( $c['author'] ) ? $c['author'] : null, $c['createdAt'], 'comment' );
	}
	foreach ( isset( $node['reviews']['nodes'] ) ? $node['reviews']['nodes'] : array() as $r ) {
		if ( ! empty( $r['submittedAt'] ) ) {
			$responses[] = array( isset( $r['author'] ) ? $r['author'] : null, $r['submittedAt'], 'review' );
		}
	}
	foreach ( $responses as $response ) {
		list( $actor, $at, $kind ) = $response;
		$login = isset( $actor['login'] ) ? $actor['login'] : '';
		if ( wp_cli_dashboard_is_bot( $login, isset( $actor['__typename'] ) ? $actor['__typename'] : null ) ) {
			continue;
		}
		$participants[] = array( $login, $kind, $at );
		if ( $login !== $author && ( null === $first_response || $at < $first_response ) ) {
			$first_response = $at;
		}
	}

	$merged_by = isset( $node['mergedBy']['login'] ) ? $node['mergedBy']['login'] : null;
	// Merging someone else's PR is a response too, even without a comment.
	if ( $is_pr && ! empty( $node['mergedAt'] ) && $merged_by && $merged_by !== $author
		&& ( null === $first_response || $node['mergedAt'] < $first_response ) ) {
		$first_response = $node['mergedAt'];
	}

	return array(
		'type'              => $is_pr ? 'pr' : 'issue',
		'repo'              => $node['repository']['nameWithOwner'],
		'number'            => (int) $node['number'],
		'title'             => $node['title'],
		'url'               => $node['url'],
		'state'             => 'OPEN' === $node['state'] ? 'open' : ( ! empty( $node['mergedAt'] ) ? 'merged' : 'closed' ),
		'draft'             => ! empty( $node['isDraft'] ),
		'author'            => $author,
		'bot'               => wp_cli_dashboard_is_bot( $author, $a_type ),
		'created_at'        => $node['createdAt'],
		'updated_at'        => $node['updatedAt'],
		'closed_at'         => $node['closedAt'],
		'merged_at'         => $is_pr ? $node['mergedAt'] : null,
		'merged_by'         => $merged_by,
		'first_response_at' => $first_response,
		'participants'      => $participants,
	);
}

/**
 * Merges contributor events from cached items into contributors.json.
 *
 * @param array $items
 */
function wp_cli_dashboard_update_contributors( $items ) {
	$events = array();
	foreach ( $items as $item ) {
		if ( ! $item['bot'] ) {
			$events[] = array( $item['author'], 'pr' === $item['type'] ? 'pr_opened' : 'issue', $item['created_at'] );
			if ( ! empty( $item['merged_at'] ) ) {
				$events[] = array( $item['author'], 'pr_merged', $item['merged_at'] );
			}
		}
		foreach ( $item['participants'] as $p ) {
			$events[] = $p;
		}
	}
	wp_cli_dashboard_add_contributor_events( $events );
}

/**
 * Adds events to contributors.json.
 *
 * @param array $events List of [ login, kind, ISO date ].
 */
function wp_cli_dashboard_add_contributor_events( $events ) {
	$contributors = wp_cli_dashboard_read_json( 'contributors.json' );
	foreach ( $events as $event ) {
		list( $login, $kind, $at ) = $event;
		if ( wp_cli_dashboard_is_bot( $login ) || empty( $at ) ) {
			continue;
		}
		$contributors[ $login ][ $kind ][] = substr( $at, 0, 10 );
	}
	foreach ( $contributors as $login => $kinds ) {
		foreach ( $kinds as $kind => $dates ) {
			$dates = array_values( array_unique( $dates ) );
			sort( $dates );
			$contributors[ $login ][ $kind ] = $dates;
		}
		ksort( $contributors[ $login ] );
	}
	ksort( $contributors, SORT_STRING | SORT_FLAG_CASE );
	wp_cli_dashboard_write_json( 'contributors.json', $contributors );
}

/**
 * One-time import of every pull request opened before the activity window,
 * so "first merged PR" means first ever, not first in 12 months.
 *
 * @param int $until
 * @return bool Whether the import completed.
 */
function wp_cli_dashboard_backfill_pr_history( $until ) {
	WP_CLI::log( 'Importing pull request history...' );
	$fields = '__typename ... on PullRequest { createdAt mergedAt author { login __typename } }';
	$nodes  = array();
	$ok     = true;
	for ( $year = 2011; $year <= (int) gmdate( 'Y', $until ); $year++ ) {
		$from = gmmktime( 0, 0, 0, 1, 1, $year );
		$to   = min( gmmktime( 0, 0, 0, 1, 1, $year + 1 ), $until );
		$ok   = wp_cli_dashboard_search_range( 'org:wp-cli is:pr', 'created', $from, $to, $nodes, $fields ) && $ok;
	}
	if ( ! $ok ) {
		WP_CLI::warning( 'Pull request history import incomplete; will retry next run.' );
		return false;
	}
	$events = array();
	foreach ( $nodes as $node ) {
		$login = isset( $node['author']['login'] ) ? $node['author']['login'] : '';
		if ( wp_cli_dashboard_is_bot( $login, isset( $node['author']['__typename'] ) ? $node['author']['__typename'] : null ) ) {
			continue;
		}
		$events[] = array( $login, 'pr_opened', $node['createdAt'] );
		if ( ! empty( $node['mergedAt'] ) ) {
			$events[] = array( $login, 'pr_merged', $node['mergedAt'] );
		}
	}
	wp_cli_dashboard_add_contributor_events( $events );
	WP_CLI::log( sprintf( 'Imported %d historical pull requests.', count( $nodes ) ) );
	return true;
}

WP_CLI::add_command( 'dashboard fetch-activity-data', 'wp_cli_dashboard_fetch_activity_data' );
