<?php
/**
 * Turns stored GitHub data into the numbers shown on the dashboard.
 *
 * All dates are handled in UTC.
 */

/**
 * Reference "now" for the build.
 *
 * @return int
 */
function wp_cli_dashboard_now() {
	static $now = null;
	if ( null === $now ) {
		$now = getenv( 'DASHBOARD_NOW' ) ? strtotime( getenv( 'DASHBOARD_NOW' ) ) : time();
	}
	return $now;
}

/**
 * The past 12 calendar months, oldest first, as 'Y-m'.
 *
 * Counted from the first of the current month so the 29th-31st don't skip
 * or repeat months.
 *
 * @return string[]
 */
function wp_cli_dashboard_months() {
	$first  = strtotime( gmdate( 'Y-m-01', wp_cli_dashboard_now() ) );
	$months = array();
	for ( $i = 11; $i >= 0; $i-- ) {
		$months[] = gmdate( 'Y-m', strtotime( "-{$i} months", $first ) );
	}
	return $months;
}

/**
 * The past 52 ISO weeks (including the current one), oldest first.
 *
 * @return array Map of 'o-W' key => Monday timestamp.
 */
function wp_cli_dashboard_weeks() {
	$monday = strtotime( 'monday this week', strtotime( gmdate( 'Y-m-d', wp_cli_dashboard_now() ) ) );
	$weeks  = array();
	for ( $i = 51; $i >= 0; $i-- ) {
		$ts                           = strtotime( "-{$i} weeks", $monday );
		$weeks[ gmdate( 'o-W', $ts ) ] = $ts;
	}
	return $weeks;
}

/**
 * X-axis ticks for weekly data: one per month, at the first week of the month.
 *
 * @param array $weeks From wp_cli_dashboard_weeks().
 * @return array
 */
function wp_cli_dashboard_week_ticks( $weeks ) {
	$ticks = array();
	$last  = null;
	$i     = 0;
	$n     = 0;
	foreach ( $weeks as $ts ) {
		$month = gmdate( 'Y-m', $ts );
		if ( $month !== $last && $i > 0 ) {
			$ticks[] = array( $i, '01' === gmdate( 'm', $ts ) ? gmdate( 'M ’y', $ts ) : gmdate( 'M', $ts ), 1 === $n++ % 2 );
		}
		$last = $month;
		$i++;
	}
	return $ticks;
}

/**
 * X-axis ticks for monthly data.
 *
 * @param array $months 'Y-m' list.
 * @return array
 */
function wp_cli_dashboard_month_ticks( $months ) {
	$ticks = array();
	foreach ( $months as $i => $month ) {
		$ts      = strtotime( $month . '-01' );
		$ticks[] = array( $i, ( 0 === $i || '01' === gmdate( 'm', $ts ) ) ? gmdate( 'M ’y', $ts ) : gmdate( 'M', $ts ), 1 === $i % 2 );
	}
	return $ticks;
}

/**
 * Summary of a daily snapshot metric.
 *
 * @param string $key
 * @return array|null
 */
function wp_cli_dashboard_metric_summary( $key ) {
	$series = wp_cli_dashboard_read_json( "metrics/{$key}.json" );
	if ( empty( $series ) ) {
		return null;
	}
	ksort( $series );
	$now    = wp_cli_dashboard_now();
	$dates  = array_keys( $series );
	$latest = end( $dates );

	// Most recent reading on or before 30 days ago.
	$then_date = gmdate( 'Y-m-d', strtotime( '-30 days', strtotime( $latest ) ) );
	$previous  = null;
	foreach ( $series as $date => $value ) {
		if ( $date > $then_date ) {
			break;
		}
		$previous = $value;
	}

	// One point per day for a year; days without a reading stay empty.
	$values = array();
	$labels = array();
	for ( $i = 364; $i >= 0; $i-- ) {
		$date     = gmdate( 'Y-m-d', strtotime( "-{$i} days", $now ) );
		$values[] = isset( $series[ $date ] ) ? $series[ $date ] : null;
		$labels[] = gmdate( 'M j, Y', strtotime( $date ) );
	}

	return array(
		'value'  => $series[ $latest ],
		'date'   => $latest,
		'delta'  => null === $previous ? null : $series[ $latest ] - $previous,
		'values' => $values,
		'labels' => $labels,
	);
}

/**
 * Cached issues and pull requests.
 *
 * @return array
 */
function wp_cli_dashboard_items() {
	static $items = null;
	if ( null === $items ) {
		$items = array_values( wp_cli_dashboard_read_json( 'activity/items.json' ) );
	}
	return $items;
}

/**
 * Weekly opened/closed counts for issues or pull requests.
 *
 * @param string $type 'issue' or 'pr'.
 * @return array|null { weeks, opened, closed, bots }
 */
function wp_cli_dashboard_weekly_activity( $type ) {
	$weeks  = wp_cli_dashboard_weeks();
	$opened = array_fill_keys( array_keys( $weeks ), 0 );
	$closed = $opened;
	$bots   = 0;
	$start  = gmdate( 'Y-m-d', reset( $weeks ) );
	$items  = wp_cli_dashboard_items();

	$bucket = function ( $date, &$counts ) use ( $start ) {
		if ( empty( $date ) || substr( $date, 0, 10 ) < $start ) {
			return;
		}
		$key = gmdate( 'o-W', strtotime( $date ) );
		if ( isset( $counts[ $key ] ) ) {
			$counts[ $key ]++;
		}
	};

	if ( ! empty( $items ) ) {
		foreach ( $items as $item ) {
			if ( $item['type'] !== $type ) {
				continue;
			}
			if ( $item['bot'] ) {
				if ( substr( $item['created_at'], 0, 10 ) >= $start ) {
					$bots++;
				}
				continue;
			}
			$bucket( $item['created_at'], $opened );
			$bucket( 'pr' === $type ? $item['merged_at'] : $item['closed_at'], $closed );
		}
	} else {
		// Pre-migration timestamp lists (bots can't be told apart there).
		$legacy = 'pr' === $type ? array( 'new-prs', 'merged-prs' ) : array( 'new-issues', 'closed-issues' );
		$a      = wp_cli_dashboard_read_json( "activity/{$legacy[0]}.json", null );
		$b      = wp_cli_dashboard_read_json( "activity/{$legacy[1]}.json", null );
		if ( null === $a ) {
			return null;
		}
		foreach ( $a as $date ) {
			$bucket( $date, $opened );
		}
		foreach ( (array) $b as $date ) {
			$bucket( $date, $closed );
		}
		$bots = null;
	}

	return array(
		'weeks'  => $weeks,
		'opened' => array_values( $opened ),
		'closed' => array_values( $closed ),
		'bots'   => $bots,
	);
}

/**
 * Percentile of a list of numbers (linear interpolation).
 *
 * @param array $values
 * @param float $p 0..1
 * @return float|null
 */
function wp_cli_dashboard_percentile( $values, $p ) {
	if ( empty( $values ) ) {
		return null;
	}
	sort( $values );
	$pos  = ( count( $values ) - 1 ) * $p;
	$base = (int) floor( $pos );
	$next = min( $base + 1, count( $values ) - 1 );
	return $values[ $base ] + ( $values[ $next ] - $values[ $base ] ) * ( $pos - $base );
}

/**
 * Formats a duration in seconds compactly ("45 min", "6.5 h", "3.2 d").
 *
 * @param float|null $seconds
 * @return string
 */
function wp_cli_dashboard_format_duration( $seconds ) {
	if ( null === $seconds ) {
		return '–';
	}
	if ( $seconds < HOUR_IN_SECONDS ) {
		return max( 1, round( $seconds / MINUTE_IN_SECONDS ) ) . ' min';
	}
	if ( $seconds < 2 * DAY_IN_SECONDS ) {
		$h = $seconds / HOUR_IN_SECONDS;
		return ( $h < 10 ? round( $h, 1 ) : round( $h ) ) . ' h';
	}
	$d = $seconds / DAY_IN_SECONDS;
	return ( $d < 10 ? round( $d, 1 ) : round( $d ) ) . ' d';
}

/**
 * Responsiveness stats for the past 90 days, compared with the 90 days before.
 *
 * @return array|null
 */
function wp_cli_dashboard_response_stats() {
	$items = wp_cli_dashboard_items();
	if ( empty( $items ) ) {
		return null;
	}
	$now     = wp_cli_dashboard_now();
	$periods = array(
		'current'  => array( gmdate( 'Y-m-d\TH:i:s\Z', $now - 90 * DAY_IN_SECONDS ), gmdate( 'Y-m-d\TH:i:s\Z', $now ) ),
		'previous' => array( gmdate( 'Y-m-d\TH:i:s\Z', $now - 180 * DAY_IN_SECONDS ), gmdate( 'Y-m-d\TH:i:s\Z', $now - 90 * DAY_IN_SECONDS ) ),
	);
	$stats = array();
	foreach ( $periods as $period => $range ) {
		$response = array(
			'issue' => array(),
			'pr'    => array(),
		);
		$waiting  = array(
			'issue' => 0,
			'pr'    => 0,
		);
		$merge    = array();
		foreach ( $items as $item ) {
			if ( $item['bot'] ) {
				continue;
			}
			if ( $item['created_at'] >= $range[0] && $item['created_at'] < $range[1] ) {
				if ( ! empty( $item['first_response_at'] ) ) {
					$response[ $item['type'] ][] = strtotime( $item['first_response_at'] ) - strtotime( $item['created_at'] );
				} elseif ( 'open' === $item['state'] ) {
					$waiting[ $item['type'] ]++;
				}
			}
			if ( ! empty( $item['merged_at'] ) && $item['merged_at'] >= $range[0] && $item['merged_at'] < $range[1] ) {
				$merge[] = strtotime( $item['merged_at'] ) - strtotime( $item['created_at'] );
			}
		}
		$stats[ $period ] = array(
			'issue_response' => array( wp_cli_dashboard_percentile( $response['issue'], 0.5 ), wp_cli_dashboard_percentile( $response['issue'], 0.9 ), count( $response['issue'] ) ),
			'pr_response'    => array( wp_cli_dashboard_percentile( $response['pr'], 0.5 ), wp_cli_dashboard_percentile( $response['pr'], 0.9 ), count( $response['pr'] ) ),
			'merge'          => array( wp_cli_dashboard_percentile( $merge, 0.5 ), wp_cli_dashboard_percentile( $merge, 0.9 ), count( $merge ) ),
			'waiting'        => $waiting,
		);
	}
	return $stats;
}

/**
 * Open pull requests with no activity in 30 days, least recently updated first.
 *
 * @return array|null
 */
function wp_cli_dashboard_stale_prs() {
	$items = wp_cli_dashboard_items();
	if ( empty( $items ) ) {
		return null;
	}
	$cutoff = gmdate( 'Y-m-d\TH:i:s\Z', wp_cli_dashboard_now() - 30 * DAY_IN_SECONDS );
	$stale  = array_filter(
		$items,
		function ( $item ) use ( $cutoff ) {
			return 'pr' === $item['type'] && 'open' === $item['state'] && $item['updated_at'] < $cutoff;
		}
	);
	usort(
		$stale,
		function ( $a, $b ) {
			return strcmp( $a['updated_at'], $b['updated_at'] );
		}
	);
	return $stale;
}

/**
 * Who reviewed and merged pull requests in the past 30 days.
 *
 * @return array|null List of { login, reviewed, merged }, busiest first.
 */
function wp_cli_dashboard_review_load() {
	$items = wp_cli_dashboard_items();
	if ( empty( $items ) ) {
		return null;
	}
	$cutoff = gmdate( 'Y-m-d\TH:i:s\Z', wp_cli_dashboard_now() - 30 * DAY_IN_SECONDS );
	$people = array();
	foreach ( $items as $item ) {
		if ( 'pr' !== $item['type'] ) {
			continue;
		}
		$reviewers = array();
		foreach ( $item['participants'] as $p ) {
			if ( 'review' === $p[1] && $p[2] >= $cutoff && $p[0] !== $item['author'] ) {
				$reviewers[ $p[0] ] = true;
			}
		}
		foreach ( array_keys( $reviewers ) as $login ) {
			$people[ $login ]['reviewed'] = ( isset( $people[ $login ]['reviewed'] ) ? $people[ $login ]['reviewed'] : 0 ) + 1;
		}
		if ( ! empty( $item['merged_at'] ) && $item['merged_at'] >= $cutoff && $item['merged_by'] && ! wp_cli_dashboard_is_bot( $item['merged_by'] ) ) {
			$login                     = $item['merged_by'];
			$people[ $login ]['merged'] = ( isset( $people[ $login ]['merged'] ) ? $people[ $login ]['merged'] : 0 ) + 1;
		}
	}
	$list = array();
	foreach ( $people as $login => $counts ) {
		$list[] = array(
			'login'    => $login,
			'reviewed' => isset( $counts['reviewed'] ) ? $counts['reviewed'] : 0,
			'merged'   => isset( $counts['merged'] ) ? $counts['merged'] : 0,
		);
	}
	usort(
		$list,
		function ( $a, $b ) {
			return ( $b['reviewed'] + $b['merged'] ) - ( $a['reviewed'] + $a['merged'] ) ?: strcasecmp( $a['login'], $b['login'] );
		}
	);
	return $list;
}

/**
 * Per-contributor first/last activity, derived from contributors.json.
 *
 * @return array Map of login => { first, first_pr, first_merged, dates, pr_dates }.
 */
function wp_cli_dashboard_contributors() {
	static $people = null;
	if ( null !== $people ) {
		return $people;
	}
	$people = array();
	foreach ( wp_cli_dashboard_read_json( 'contributors.json' ) as $login => $kinds ) {
		if ( wp_cli_dashboard_is_bot( $login ) ) {
			continue;
		}
		$all = array();
		foreach ( $kinds as $dates ) {
			$all = array_merge( $all, $dates );
		}
		$all = array_values( array_unique( $all ) );
		sort( $all );
		$people[ $login ] = array(
			'first'        => $all ? $all[0] : null,
			'first_merged' => ! empty( $kinds['pr_merged'] ) ? min( $kinds['pr_merged'] ) : null,
			'dates'        => $all,
			'pr_dates'     => isset( $kinds['pr_opened'] ) ? $kinds['pr_opened'] : array(),
		);
	}
	return $people;
}

/**
 * Contributor numbers for the dashboard.
 *
 * @return array
 */
function wp_cli_dashboard_contributor_stats() {
	$people  = wp_cli_dashboard_contributors();
	$now     = wp_cli_dashboard_now();
	$cut30   = gmdate( 'Y-m-d', $now - 30 * DAY_IN_SECONDS );
	$months  = wp_cli_dashboard_months();
	$monthly = array(
		'new'         => array_fill_keys( $months, 0 ),
		'new_code'    => array_fill_keys( $months, 0 ),
		'active'      => array_fill_keys( $months, 0 ),
		'active_code' => array_fill_keys( $months, 0 ),
	);
	$active     = array();
	$has_typed  = false;
	$cohort     = array( 0, 0 );
	$cohort_pr  = array( 0, 0 );
	$cohort_end = gmdate( 'Y-m-d', $now - 90 * DAY_IN_SECONDS );
	$cohort_beg = gmdate( 'Y-m-d', strtotime( '-12 months', $now ) );

	foreach ( $people as $login => $p ) {
		if ( null !== $p['first_merged'] || ! empty( $p['pr_dates'] ) ) {
			$has_typed = true;
		}
		$last = end( $p['dates'] );
		if ( $last >= $cut30 ) {
			$active[ $login ] = array(
				'login'  => $login,
				'new'    => $p['first'] >= $cut30,
				'new_pr' => null !== $p['first_merged'] && $p['first_merged'] >= $cut30,
				'last'   => $last,
			);
		}
		$first_month = substr( $p['first'], 0, 7 );
		if ( isset( $monthly['new'][ $first_month ] ) ) {
			$monthly['new'][ $first_month ]++;
		}
		if ( $p['first_merged'] && isset( $monthly['new_code'][ substr( $p['first_merged'], 0, 7 ) ] ) ) {
			$monthly['new_code'][ substr( $p['first_merged'], 0, 7 ) ]++;
		}
		foreach ( array_unique( array_map( 'wp_cli_dashboard_month_of', $p['dates'] ) ) as $m ) {
			if ( isset( $monthly['active'][ $m ] ) ) {
				$monthly['active'][ $m ]++;
			}
		}
		foreach ( array_unique( array_map( 'wp_cli_dashboard_month_of', $p['pr_dates'] ) ) as $m ) {
			if ( isset( $monthly['active_code'][ $m ] ) ) {
				$monthly['active_code'][ $m ]++;
			}
		}

		// Retention: came back within 90 days of their first contribution.
		if ( $p['first'] >= $cohort_beg && $p['first'] < $cohort_end ) {
			$cohort[0]++;
			$limit = gmdate( 'Y-m-d', strtotime( $p['first'] ) + 90 * DAY_IN_SECONDS );
			foreach ( $p['dates'] as $d ) {
				if ( $d > $p['first'] && $d <= $limit ) {
					$cohort[1]++;
					break;
				}
			}
		}
		if ( $p['first_merged'] && $p['first_merged'] >= $cohort_beg && $p['first_merged'] < $cohort_end ) {
			$cohort_pr[0]++;
			$limit = gmdate( 'Y-m-d', strtotime( $p['first_merged'] ) + 90 * DAY_IN_SECONDS );
			$first_pr = min( $p['pr_dates'] ? $p['pr_dates'] : array( $p['first_merged'] ) );
			foreach ( $p['pr_dates'] as $d ) {
				if ( $d > $first_pr && $d <= $limit ) {
					$cohort_pr[1]++;
					break;
				}
			}
		}
	}

	uasort(
		$active,
		function ( $a, $b ) {
			return ( $b['new_pr'] - $a['new_pr'] ) ?: ( $b['new'] - $a['new'] ) ?: strcasecmp( $a['login'], $b['login'] );
		}
	);

	return array(
		'active'    => $active,
		'monthly'   => $monthly,
		'months'    => $months,
		'has_typed' => $has_typed,
		'retention' => array(
			'any' => $cohort,
			'pr'  => $cohort_pr,
		),
	);
}

/**
 * 'Y-m' of a 'Y-m-d' date.
 *
 * @param string $date
 * @return string
 */
function wp_cli_dashboard_month_of( $date ) {
	return substr( $date, 0, 7 );
}

/**
 * Repository rows, normalized across data schema versions.
 *
 * @return array
 */
function wp_cli_dashboard_repositories() {
	$rows = array();
	foreach ( wp_cli_dashboard_get_config_data( 'github_repositories' ) as $repo ) {
		$short = str_replace( 'wp-cli/', '', $repo );
		$d     = wp_cli_dashboard_read_json( "repositories/{$short}.json" );
		$row   = array(
			'repo'        => $repo,
			'name'        => $short,
			'issues'      => isset( $d['open_issues'] ) ? (int) $d['open_issues'] : null,
			'prs'         => isset( $d['open_pull_requests'] ) ? (int) $d['open_pull_requests'] : null,
			'pushed_at'   => isset( $d['pushed_at'] ) ? $d['pushed_at'] : null,
			'milestone'   => null,
			'release'     => null,
			'unreleased'  => null,
			'ci'          => array(
				'testing'      => null,
				'code_quality' => null,
			),
			'ci_known'    => false,
		);
		if ( isset( $d['schema'] ) && 2 === $d['schema'] ) {
			$row['milestone']  = $d['milestone'];
			$row['release']    = $d['release'];
			$row['unreleased'] = $d['unreleased'];
			$row['ci']         = $d['ci'];
			$row['ci_known']   = true;
		} else {
			if ( ! empty( $d['active_milestone'] ) ) {
				$m                = $d['active_milestone'];
				$row['milestone'] = array(
					'title'  => $m['title'],
					'url'    => $m['html_url'],
					'open'   => $m['open_issues'],
					'closed' => $m['closed_issues'],
				);
			}
			if ( ! empty( $d['latest_release'] ) ) {
				$r              = $d['latest_release'];
				$row['release'] = array(
					'tag'          => $r['tag_name'],
					'url'          => $r['html_url'],
					'published_at' => $r['published_at'],
				);
			}
		}

		$failing = 0;
		foreach ( $row['ci'] as $run ) {
			if ( $run && ! in_array( $run['conclusion'], array( 'success', 'skipped', 'neutral' ), true ) ) {
				$failing++;
			}
		}
		$row['failing'] = $failing;
		$unreleased     = isset( $row['unreleased']['human'] ) ? $row['unreleased']['human'] : 0;
		// Default order: broken builds, then repos with the most unreleased
		// work, then the most open pull requests.
		$row['attention'] = $failing * 100000 + min( $unreleased, 999 ) * 100 + min( (int) $row['prs'], 99 );
		$rows[]           = $row;
	}
	usort(
		$rows,
		function ( $a, $b ) {
			return ( $b['attention'] - $a['attention'] ) ?: strcmp( $a['name'], $b['name'] );
		}
	);
	return $rows;
}

/**
 * Timestamp of the most recent data refresh.
 *
 * @return int
 */
function wp_cli_dashboard_last_updated() {
	// The workflow fetches right before building, so build time is the best
	// proxy; prefer the recorded fetch time when there is one.
	$meta = wp_cli_dashboard_read_json( 'activity/meta.json' );
	if ( ! empty( $meta['last_fetch'] ) ) {
		return min( strtotime( $meta['last_fetch'] ), wp_cli_dashboard_now() );
	}
	return wp_cli_dashboard_now();
}
