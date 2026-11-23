<?php
/**
 * Basic entry point.
 */

$now          = wp_cli_dashboard_now();
$updated      = wp_cli_dashboard_last_updated();
$github_data  = wp_cli_dashboard_get_config_data( 'github_data' );
$responses    = wp_cli_dashboard_response_stats();
$stale        = wp_cli_dashboard_stale_prs();
$review_load  = wp_cli_dashboard_review_load();
$contributors = wp_cli_dashboard_contributor_stats();
$repositories = wp_cli_dashboard_repositories();
$pending_note = 'Collected by the hourly activity fetch; available after its next run.';

$sort_order = array(
	'open_pull_requests_awaiting_review',
	'open_pull_requests',
	'open_issues_no_reply',
	'open_issues',
	'open_issues_label_bug',
);
$metrics = array();
foreach ( array_merge( $sort_order, array_keys( $github_data ) ) as $key ) {
	if ( isset( $github_data[ $key ] ) && ! isset( $metrics[ $key ] ) ) {
		$metrics[ $key ] = $github_data[ $key ];
	}
}

/**
 * Renders a change indicator.
 *
 * @param int|float|null $delta  Change (positive = increase).
 * @param string         $better 'lower', 'higher' or 'neutral'.
 * @param string         $text   Display text for the change.
 * @param string         $suffix Context, e.g. "vs 30 days ago".
 */
$delta_html = function ( $delta, $better, $text, $suffix ) {
	if ( null === $delta ) {
		return '<p class="delta delta--none">No earlier data</p>';
	}
	if ( 0 == $delta ) {
		return sprintf( '<p class="delta delta--flat"><span aria-hidden="true">→</span> No change <span class="delta__ctx">%s</span></p>', wp_cli_dashboard_esc( $suffix ) );
	}
	$state = 'neutral';
	if ( 'neutral' !== $better ) {
		$state = ( $delta < 0 ) === ( 'lower' === $better ) ? 'good' : 'bad';
	}
	return sprintf(
		'<p class="delta delta--%s"><span aria-hidden="true">%s</span> %s <span class="delta__ctx">%s</span><span class="visually-hidden">%s</span></p>',
		$state,
		$delta > 0 ? '▲' : '▼',
		wp_cli_dashboard_esc( $text ),
		wp_cli_dashboard_esc( $suffix ),
		'good' === $state ? ' (improved)' : ( 'bad' === $state ? ' (worse)' : '' )
	);
};

$avatar = function ( $login, $size = 24 ) {
	return sprintf(
		'<img class="avatar" src="https://github.com/%s.png?size=%d" width="%d" height="%d" alt="" loading="lazy">',
		rawurlencode( $login ),
		$size * 2,
		$size,
		$size
	);
};

$ago = function ( $date ) use ( $now ) {
	return $date ? human_time_diff( strtotime( $date ), $now ) : '–';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WP-CLI Contributor Dashboard</title>
<meta name="description" content="Issue, pull request, contributor and repository health for the WP-CLI project, rebuilt hourly.">
<meta name="color-scheme" content="light dark">
<link rel="icon" type="image/x-icon" href="https://wp-cli.org/assets/img/favicon.jpg" />
<style>
<?php echo file_get_contents( __DIR__ . '/assets/style.css' ); ?>
</style>
</head>

<body>

<header class="site-header">
	<div class="container site-header__inner">
		<div>
			<p class="brand"><span class="brand__prompt" aria-hidden="true">$</span> wp-cli <span class="brand__sep">/</span> contributor dashboard</p>
			<p class="site-header__meta">
				Updated <time datetime="<?php echo gmdate( 'c', $updated ); ?>" data-relative><?php echo gmdate( 'M j, Y H:i', $updated ); ?> UTC</time>
				· rebuilt hourly ·
				<a href="https://github.com/wp-cli/dashboard/issues">Suggest an improvement</a>
			</p>
		</div>
		<nav class="site-nav" aria-label="Sections">
			<a href="#backlog">Backlog</a>
			<a href="#responsiveness">Responsiveness</a>
			<a href="#activity">Activity</a>
			<a href="#attention">Needs attention</a>
			<a href="#contributors">Contributors</a>
			<a href="#repositories">Repositories</a>
		</nav>
	</div>
</header>

<main class="container">

	<section id="backlog" aria-labelledby="backlog-title">
		<div class="section-head">
			<h2 id="backlog-title">Backlog</h2>
			<p>Open items across all wp-cli repositories, with the change over the past 30 days and a 12-month trend.</p>
		</div>
		<div class="tiles">
			<?php
			foreach ( $metrics as $key => $metric ) :
				$summary  = wp_cli_dashboard_metric_summary( $key );
				$base_url = false !== strpos( $key, 'pull_request' ) ? 'https://github.com/pulls?q=' : 'https://github.com/issues?q=';
				$better   = isset( $metric['better'] ) ? $metric['better'] : 'neutral';
				?>
				<article class="tile">
					<h3 class="tile__label"><a href="<?php echo wp_cli_dashboard_esc( $base_url . rawurlencode( $metric['search'] ) ); ?>"><?php echo wp_cli_dashboard_esc( $metric['label'] ); ?></a></h3>
					<?php if ( $summary ) : ?>
						<p class="tile__value num"><?php echo number_format( $summary['value'] ); ?></p>
						<?php echo $delta_html( $summary['delta'], $better, null === $summary['delta'] ? '' : number_format( abs( $summary['delta'] ) ), 'vs 30 days ago' ); ?>
						<?php
						echo wp_cli_dashboard_chart(
							array(
								'type'   => 'spark',
								'height' => 44,
								'labels' => $summary['labels'],
								'series' => array(
									array(
										'name'   => $metric['label'],
										'color'  => '--series-1',
										'values' => $summary['values'],
									),
								),
								'label'  => sprintf( '%s over the past 12 months', $metric['label'] ),
							)
						);
						?>
					<?php else : ?>
						<p class="tile__value num">–</p>
						<p class="delta delta--none">No data yet</p>
					<?php endif; ?>
				</article>
			<?php endforeach; ?>
		</div>
	</section>

	<section id="responsiveness" aria-labelledby="responsiveness-title">
		<div class="section-head">
			<h2 id="responsiveness-title">Responsiveness</h2>
			<p>Items opened in the past 90 days by people (not bots), compared with the 90 days before. A response is the first comment, review or merge by someone other than the author.</p>
		</div>
		<?php if ( $responses ) : ?>
			<div class="tiles tiles--3">
				<?php
				$response_tiles = array(
					'issue_response' => 'First response to issues',
					'pr_response'    => 'First response to pull requests',
					'merge'          => 'Time to merge',
				);
				foreach ( $response_tiles as $key => $label ) :
					list( $median, $p90, $count ) = $responses['current'][ $key ];
					$previous                     = $responses['previous'][ $key ][0];
					$delta                        = ( null === $median || null === $previous ) ? null : $median - $previous;
					?>
					<article class="tile">
						<h3 class="tile__label"><?php echo wp_cli_dashboard_esc( $label ); ?></h3>
						<p class="tile__value num"><?php echo wp_cli_dashboard_format_duration( $median ); ?> <span class="tile__unit">median</span></p>
						<?php echo $delta_html( $delta, 'lower', null === $delta ? '' : wp_cli_dashboard_format_duration( abs( $delta ) ), 'vs previous 90 days' ); ?>
						<dl class="tile__facts">
							<div><dt>90th percentile</dt><dd class="num"><?php echo wp_cli_dashboard_format_duration( $p90 ); ?></dd></div>
							<div><dt><?php echo 'merge' === $key ? 'Merged' : 'Answered'; ?></dt><dd class="num"><?php echo number_format( $count ); ?></dd></div>
							<?php if ( 'merge' !== $key ) : ?>
								<?php $waiting = $responses['current']['waiting'][ 'issue_response' === $key ? 'issue' : 'pr' ]; ?>
								<div><dt>Still waiting</dt><dd class="num"><?php echo number_format( $waiting ); ?></dd></div>
							<?php endif; ?>
						</dl>
					</article>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<p class="empty"><?php echo $pending_note; ?></p>
		<?php endif; ?>
	</section>

	<section id="activity" aria-labelledby="activity-title">
		<div class="section-head">
			<h2 id="activity-title">Activity</h2>
			<p>Opened (up) versus closed or merged (down) per week over the past 52 weeks. Bars below the line outpacing bars above it means the backlog is shrinking.</p>
		</div>
		<div class="panels">
			<?php
			$activity_charts = array(
				'issue' => array( 'Issues per week', 'Opened', 'Closed', 'issues' ),
				'pr'    => array( 'Pull requests per week', 'Opened', 'Merged', 'pull requests' ),
			);
			foreach ( $activity_charts as $type => $labels ) :
				$weekly = wp_cli_dashboard_weekly_activity( $type );
				?>
				<article class="panel">
					<h3><?php echo $labels[0]; ?></h3>
					<?php if ( $weekly ) : ?>
						<?php
						$opened = array_sum( $weekly['opened'] );
						$closed = array_sum( $weekly['closed'] );
						$net    = $opened - $closed;
						echo wp_cli_dashboard_legend(
							array(
								array( $labels[1], '--series-1', number_format( $opened ) ),
								array( $labels[2], '--series-2', number_format( $closed ) ),
							)
						);
						$week_labels = array();
						foreach ( $weekly['weeks'] as $ts ) {
							$week_labels[] = 'Week of ' . gmdate( 'M j, Y', $ts );
						}
						$series = array(
							array(
								'name'   => $labels[1],
								'color'  => '--series-1',
								'values' => $weekly['opened'],
							),
							array(
								'name'   => $labels[2],
								'color'  => '--series-2',
								'values' => $weekly['closed'],
								'sign'   => -1,
							),
						);
						echo wp_cli_dashboard_chart(
							array(
								'type'   => 'bars',
								'height' => 220,
								'labels' => $week_labels,
								'ticks'  => wp_cli_dashboard_week_ticks( $weekly['weeks'] ),
								'series' => $series,
								'label'  => sprintf( '%s opened and %s per week. %s opened, %s %s over 52 weeks.', ucfirst( $labels[3] ), strtolower( $labels[2] ), number_format( $opened ), number_format( $closed ), strtolower( $labels[2] ) ),
							)
						);
						?>
						<p class="panel__note">
							Net change: <strong class="num"><?php echo ( $net > 0 ? '+' : ( $net < 0 ? '−' : '' ) ) . number_format( abs( $net ) ); ?></strong> <?php echo $labels[3]; ?>.
							<?php if ( null === $weekly['bots'] ) : ?>
								Includes bot activity until the next data refresh.
							<?php elseif ( $weekly['bots'] ) : ?>
								Excludes <?php echo number_format( $weekly['bots'] ); ?> opened by bots.
							<?php endif; ?>
						</p>
						<?php echo wp_cli_dashboard_chart_table( $week_labels, $series, 'Week' ); ?>
					<?php else : ?>
						<p class="empty"><?php echo $pending_note; ?></p>
					<?php endif; ?>
				</article>
			<?php endforeach; ?>
		</div>
	</section>

	<section id="attention" aria-labelledby="attention-title">
		<div class="section-head">
			<h2 id="attention-title">Needs attention</h2>
			<p>Pull requests that have gone quiet, and who has been reviewing and merging over the past 30 days.</p>
		</div>
		<div class="panels panels--wide-left">
			<article class="panel">
				<h3>Stale pull requests <span class="count num"><?php echo null === $stale ? '' : count( $stale ); ?></span></h3>
				<?php if ( null === $stale ) : ?>
					<p class="empty"><?php echo $pending_note; ?></p>
				<?php elseif ( empty( $stale ) ) : ?>
					<p class="empty">No open pull request has been idle for 30 days.</p>
				<?php else : ?>
					<p class="panel__note">Open, no activity in 30+ days, least recently touched first.</p>
					<ul class="pr-list">
						<?php foreach ( array_slice( $stale, 0, 10 ) as $pr ) : ?>
							<li>
								<a class="pr-list__title" href="<?php echo wp_cli_dashboard_esc( $pr['url'] ); ?>"><?php echo wp_cli_dashboard_esc( $pr['title'] ); ?></a>
								<span class="pr-list__meta">
									<span class="mono"><?php echo wp_cli_dashboard_esc( str_replace( 'wp-cli/', '', $pr['repo'] ) . '#' . $pr['number'] ); ?></span>
									· by <?php echo wp_cli_dashboard_esc( $pr['author'] ); ?><?php echo $pr['bot'] ? ' <span class="tag">bot</span>' : ''; ?>
									<?php echo $pr['draft'] ? '<span class="tag">draft</span>' : ''; ?>
									· idle <strong><?php echo $ago( $pr['updated_at'] ); ?></strong>
									· opened <?php echo $ago( $pr['created_at'] ); ?> ago
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ( count( $stale ) > 10 ) : ?>
						<p class="panel__more"><a href="https://github.com/pulls?q=<?php echo rawurlencode( 'is:open is:pr org:wp-cli sort:updated-asc updated:<' . gmdate( 'Y-m-d', $now - 30 * DAY_IN_SECONDS ) ); ?>">All <?php echo count( $stale ); ?> stale pull requests on GitHub →</a></p>
					<?php endif; ?>
				<?php endif; ?>
			</article>
			<article class="panel">
				<h3>Review load <span class="panel__sub">past 30 days</span></h3>
				<?php if ( null === $review_load ) : ?>
					<p class="empty"><?php echo $pending_note; ?></p>
				<?php elseif ( empty( $review_load ) ) : ?>
					<p class="empty">No reviews or merges in the past 30 days.</p>
				<?php else : ?>
					<?php
					$mergers = count( array_filter( $review_load, function ( $p ) { return $p['merged'] > 0; } ) );
					$reviewers = count( array_filter( $review_load, function ( $p ) { return $p['reviewed'] > 0; } ) );
					$max_load  = max( array_map( function ( $p ) { return max( $p['reviewed'], $p['merged'] ); }, $review_load ) );
					?>
					<p class="panel__note"><strong class="num"><?php echo $reviewers; ?></strong> <?php echo 1 === $reviewers ? 'person' : 'people'; ?> reviewed and <strong class="num"><?php echo $mergers; ?></strong> merged pull requests.</p>
					<div class="table-scroll">
						<table class="load-table">
							<thead><tr><th scope="col">Person</th><th scope="col" class="num">Reviewed</th><th scope="col" class="num">Merged</th></tr></thead>
							<tbody>
							<?php foreach ( array_slice( $review_load, 0, 10 ) as $person ) : ?>
								<tr>
									<th scope="row"><a class="person" href="https://github.com/<?php echo rawurlencode( $person['login'] ); ?>"><?php echo $avatar( $person['login'], 20 ); ?><?php echo wp_cli_dashboard_esc( $person['login'] ); ?></a></th>
									<td class="num"><span class="inline-bar" style="--w: <?php echo round( $person['reviewed'] / $max_load * 100 ); ?>%"></span><span class="inline-num"><?php echo $person['reviewed']; ?></span></td>
									<td class="num"><span class="inline-bar" style="--w: <?php echo round( $person['merged'] / $max_load * 100 ); ?>%"></span><span class="inline-num"><?php echo $person['merged']; ?></span></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</article>
		</div>
	</section>

	<section id="contributors" aria-labelledby="contributors-title">
		<div class="section-head">
			<h2 id="contributors-title">Contributors</h2>
			<p>People (not bots) who opened, commented on or reviewed issues and pull requests. “New” means their first contribution to WP-CLI ever.</p>
		</div>
		<?php
		$active      = $contributors['active'];
		$new_count   = count( array_filter( $active, function ( $p ) { return $p['new']; } ) );
		$new_pr      = count( array_filter( $active, function ( $p ) { return $p['new_pr']; } ) );
		$retention   = $contributors['retention'];
		$pct         = function ( $pair ) {
			return $pair[0] ? round( $pair[1] / $pair[0] * 100 ) . '%' : '–';
		};
		?>
		<div class="tiles tiles--4">
			<article class="tile">
				<h3 class="tile__label">Active, past 30 days</h3>
				<p class="tile__value num"><?php echo count( $active ); ?></p>
			</article>
			<article class="tile">
				<h3 class="tile__label">New, past 30 days</h3>
				<p class="tile__value num"><?php echo $new_count; ?></p>
				<p class="tile__note">first contribution of any kind</p>
			</article>
			<article class="tile">
				<h3 class="tile__label">First PR merged, past 30 days</h3>
				<p class="tile__value num"><?php echo $contributors['has_typed'] ? $new_pr : '–'; ?></p>
				<p class="tile__note"><?php echo $contributors['has_typed'] ? 'first-time code contributors' : 'available after the next data refresh'; ?></p>
			</article>
			<article class="tile">
				<h3 class="tile__label">Came back within 90 days</h3>
				<p class="tile__value num"><?php echo $pct( $retention['any'] ); ?></p>
				<p class="tile__note">
					of <?php echo number_format( $retention['any'][0] ); ?> newcomers from 3–12 months ago<?php if ( $retention['pr'][0] ) : ?>; <strong class="num"><?php echo $pct( $retention['pr'] ); ?></strong> of first-time PR authors opened another PR<?php endif; ?>
				</p>
			</article>
		</div>

		<div class="panels">
			<?php
			$month_labels = array_map(
				function ( $m ) {
					return gmdate( 'F Y', strtotime( $m . '-01' ) );
				},
				$contributors['months']
			);
			$contributor_charts = array(
				array(
					'New contributors per month',
					array( 'First contribution', '--series-1', 'new' ),
					array( 'First merged PR', '--series-2', 'new_code' ),
				),
				array(
					'Active contributors per month',
					array( 'Any contribution', '--series-1', 'active' ),
					array( 'Opened a PR', '--series-2', 'active_code' ),
				),
			);
			foreach ( $contributor_charts as $chart ) :
				$series = array();
				$legend = array();
				foreach ( array( $chart[1], $chart[2] ) as $s ) {
					if ( ! $contributors['has_typed'] && '--series-2' === $s[1] ) {
						continue;
					}
					$values   = array_values( $contributors['monthly'][ $s[2] ] );
					$series[] = array(
						'name'   => $s[0],
						'color'  => $s[1],
						'values' => $values,
					);
					$legend[] = array( $s[0], $s[1], end( $values ) . ' this month' );
				}
				?>
				<article class="panel">
					<h3><?php echo $chart[0]; ?></h3>
					<?php echo wp_cli_dashboard_legend( $legend ); ?>
					<?php
					echo wp_cli_dashboard_chart(
						array(
							'type'   => 'line',
							'height' => 180,
							'labels' => $month_labels,
							'ticks'  => wp_cli_dashboard_month_ticks( $contributors['months'] ),
							'series' => $series,
							'label'  => $chart[0] . ' over the past 12 months',
						)
					);
					echo wp_cli_dashboard_chart_table( $month_labels, $series, 'Month' );
					?>
				</article>
			<?php endforeach; ?>
		</div>

		<article class="panel">
			<h3>Active in the past 30 days <span class="count num"><?php echo count( $active ); ?></span></h3>
			<?php if ( empty( $active ) ) : ?>
				<p class="empty">Nobody yet.</p>
			<?php else : ?>
				<ul class="people">
					<?php foreach ( $active as $person ) : ?>
						<li>
							<a class="person" href="https://github.com/<?php echo rawurlencode( $person['login'] ); ?>">
								<?php echo $avatar( $person['login'], 24 ); ?>
								<span><?php echo wp_cli_dashboard_esc( $person['login'] ); ?></span>
								<?php if ( $person['new_pr'] ) : ?>
									<span class="tag tag--accent">first PR</span>
								<?php elseif ( $person['new'] ) : ?>
									<span class="tag tag--accent">new</span>
								<?php endif; ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</article>
	</section>

	<section id="repositories" aria-labelledby="repositories-title">
		<div class="section-head">
			<h2 id="repositories-title">Repositories</h2>
			<p>Sorted by what needs attention: failing builds first, then the most unreleased commits by people, then open pull requests. Select a column heading to re-sort.</p>
		</div>
		<div class="table-scroll table-scroll--repos">
			<table class="repo-table" data-sortable>
				<thead>
					<tr>
						<th scope="col" aria-sort="none"><button type="button" data-sort="text">Repository</button></th>
						<th scope="col" class="num"><button type="button" data-sort="num">Issues</button></th>
						<th scope="col" class="num"><button type="button" data-sort="num">PRs</button></th>
						<th scope="col" class="num"><button type="button" data-sort="num" title="Commits on the default branch since the latest release, excluding bots">Unreleased</button></th>
						<th scope="col"><button type="button" data-sort="num">Latest release</button></th>
						<th scope="col"><button type="button" data-sort="num">Milestone</button></th>
						<th scope="col"><button type="button" data-sort="num">Tests</button></th>
						<th scope="col"><button type="button" data-sort="num">Code quality</button></th>
						<th scope="col"><button type="button" data-sort="num">Last push</button></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $repositories as $row ) : ?>
					<tr data-default="<?php echo $row['attention']; ?>">
						<th scope="row" data-value="<?php echo wp_cli_dashboard_esc( $row['name'] ); ?>"><a href="https://github.com/<?php echo wp_cli_dashboard_esc( $row['repo'] ); ?>"><?php echo wp_cli_dashboard_esc( $row['name'] ); ?></a></th>
						<td class="num" data-value="<?php echo (int) $row['issues']; ?>"><a href="https://github.com/<?php echo wp_cli_dashboard_esc( $row['repo'] ); ?>/issues"><?php echo null === $row['issues'] ? '–' : $row['issues']; ?></a></td>
						<td class="num" data-value="<?php echo (int) $row['prs']; ?>"><a href="https://github.com/<?php echo wp_cli_dashboard_esc( $row['repo'] ); ?>/pulls"><?php echo null === $row['prs'] ? '–' : $row['prs']; ?></a></td>
						<?php if ( $row['unreleased'] ) : ?>
							<td class="num" data-value="<?php echo (int) $row['unreleased']['human']; ?>">
								<a href="<?php echo wp_cli_dashboard_esc( $row['unreleased']['url'] ); ?>" title="<?php echo wp_cli_dashboard_esc( sprintf( '%d commits since %s, %d by bots', $row['unreleased']['total'], $row['release']['tag'], $row['unreleased']['total'] - $row['unreleased']['human'] ) ); ?>"><?php echo (int) $row['unreleased']['human']; ?></a>
							</td>
						<?php else : ?>
							<td class="num muted" data-value="-1">–</td>
						<?php endif; ?>
						<?php if ( $row['release'] ) : ?>
							<?php $published = ! empty( $row['release']['published_at'] ) ? strtotime( $row['release']['published_at'] ) : 0; ?>
							<td data-value="<?php echo $published; ?>"><a class="mono" href="<?php echo wp_cli_dashboard_esc( $row['release']['url'] ); ?>"><?php echo wp_cli_dashboard_esc( $row['release']['tag'] ); ?></a><?php if ( $published ) : ?> <span class="muted"><?php echo human_time_diff( $published, $now ); ?> ago</span><?php endif; ?></td>
						<?php else : ?>
							<td class="muted" data-value="0">None</td>
						<?php endif; ?>
						<?php if ( $row['milestone'] ) : ?>
							<?php
							$total    = $row['milestone']['open'] + $row['milestone']['closed'];
							$progress = $total ? $row['milestone']['closed'] / $total : 0;
							?>
							<td data-value="<?php echo (int) $row['milestone']['open']; ?>">
								<a class="mono" href="<?php echo wp_cli_dashboard_esc( $row['milestone']['url'] ); ?>">v<?php echo wp_cli_dashboard_esc( $row['milestone']['title'] ); ?></a>
								<span class="muted"><?php echo (int) $row['milestone']['open']; ?> open</span>
								<span class="progress" style="--p: <?php echo round( $progress * 100 ); ?>%" title="<?php echo round( $progress * 100 ); ?>% closed"></span>
							</td>
						<?php else : ?>
							<td class="muted" data-value="-1">None</td>
						<?php endif; ?>
						<?php
						foreach ( array( 'testing', 'code_quality' ) as $workflow ) :
							$run = $row['ci'][ $workflow ];
							if ( ! $row['ci_known'] ) :
								?>
								<td class="muted" data-value="0">–</td>
							<?php elseif ( ! $run ) : ?>
								<td class="muted" data-value="0">None</td>
							<?php else : ?>
								<?php $ok = in_array( $run['conclusion'], array( 'success', 'skipped', 'neutral' ), true ); ?>
								<td data-value="<?php echo $ok ? 1 : 2; ?>"><a class="status status--<?php echo $ok ? 'good' : 'bad'; ?>" href="<?php echo wp_cli_dashboard_esc( $run['url'] ); ?>"><span aria-hidden="true"><?php echo $ok ? '✓' : '✕'; ?></span> <?php echo $ok ? 'Passing' : ucfirst( str_replace( '_', ' ', $run['conclusion'] ) ); ?></a></td>
							<?php endif; ?>
						<?php endforeach; ?>
						<?php $pushed = $row['pushed_at'] ? strtotime( $row['pushed_at'] ) : 0; ?>
						<td data-value="<?php echo $pushed; ?>" class="nowrap"><?php echo $pushed ? human_time_diff( $pushed, $now ) . ' ago' : '–'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>

</main>

<footer class="site-footer">
	<div class="container">
		<p>Data from the GitHub API for the <?php echo count( $repositories ); ?> repositories in <a href="https://github.com/wp-cli/dashboard/blob/main/config.yml">config.yml</a>. Bots are excluded from people counts. Source on <a href="https://github.com/wp-cli/dashboard">GitHub</a>.</p>
	</div>
</footer>

<div class="chart-tip" role="status" aria-live="polite" hidden></div>
<script>
<?php echo file_get_contents( __DIR__ . '/assets/charts.js' ); ?>
</script>
</body>
</html>
