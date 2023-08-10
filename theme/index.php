<?php
/**
 * Basic entry point.
 */
?>

<!DOCTYPE html>
<html>
<head>
<title>WP-CLI Contributor Dashboard</title>

<style>
<?php echo file_get_contents( __DIR__ . '/assets/style.css' ); ?>
</style>

<link rel="icon" type="image/x-icon" href="https://wp-cli.org/assets/img/favicon.jpg" />
<link rel="shortcut icon" href="https://wp-cli.org/assets/img/favicon.jpg" />

<link rel="stylesheet" href="https://cdn.jsdelivr.net/chartist.js/latest/chartist.min.css">
<script src="https://cdn.jsdelivr.net/chartist.js/latest/chartist.min.js"></script>

</head>

<body>

	<header class="container">
		<h1>WP-CLI Contributor Dashboard</h1>
		<p>Dashboard is rebuilt every 20 minutes. <a href="https://github.com/wp-cli/dashboard/issues" target="_blank">Create an issue</a> to suggest an improvement.</p>
	</header>

	<main class="container">

		<h2>Key Metrics</h2>

		<div class="grid">
			<?php
			$github_data = wp_cli_dashboard_get_config_data( 'github_data' );
			?>
			<?php
				$sort_order = array(
					'open_pull_requests_awaiting_review',
					'open_pull_requests',
					'open_issues_no_label',
					'open_issues',
					'open_issues_label_bug',
				);
				$sorted_data = array();
				foreach ( $sort_order as $key ) {
					$sorted_data[ $key ] = $github_data[ $key ];
				}
				foreach ( $github_data as $key => $metric ) {
					if ( ! in_array( $key, $sort_order, true ) ) {
						$sorted_data[ $key ] = $metric;
					}
				}

				foreach ( $sorted_data as $key => $metric ) :
					$link = 'https://github.com/issues?q=' . rawurlencode( $metric['search'] );
				?>
				<div class="grid-cell">
					<h3><a href="<?php echo $link; ?>" target="_blank"><?php echo $metric['label']; ?></a></h3>
					<?php echo wp_cli_dashboard_get_template_part( 'parts/github-chart', array( 'key' => $key ) ); ?>
				</div>
			<?php endforeach; ?>

		</div>

		<h2>Contributors</h2>

		<div class="grid">
			<div class="grid-cell">
				<h3>New Contributors (Past 30 Days)</h3>
				<?php
				$new_contributors  = [];
				$new_contrib_users = [];
				foreach ( glob( WP_CLI_DASHBOARD_BASE_DIR . '/github-data/contributors/*' ) as $file ) {
					$contributor = basename( $file );
					$dates       = explode( PHP_EOL, file_get_contents( $file ) );
					$is_new      = false;
					foreach ( $dates as $date ) {
						if ( strtotime( $date ) > strtotime( '30 days ago' ) ) {
							$is_new = true;
						}
						if ( strtotime( $date ) < strtotime( '30 days ago' ) ) {
							$is_new = false;
						}
					}
					if ( $is_new ) {
						$new_contrib_users[] = $contributor;
						$new_contributors[]  = '<a href="' . sprintf( 'https://github.com/%s', $contributor ) . '" target="_blank">' . $contributor . '</a>';
					}
				}
				?>
				<p><?php echo ! empty( $new_contributors ) ? implode( ', ', $new_contributors ) : '<em>None</em>'; ?></p>
			</div>
			<div class="grid-cell" style="grid-column: span 2">
				<h3>New Contributors (Past 12 Months)</h3>
				<?php
				$new_contributors = [];
				$all_new_contribs = [];
				for ( $i = 0; $i < 12; $i++ ) {
					$new_contributors[ gmdate( 'Y-m', strtotime( '-' . $i . ' months' ) ) ] = [];
				}
				foreach ( glob( WP_CLI_DASHBOARD_BASE_DIR . '/github-data/contributors/*' ) as $file ) {
					$contributor   = basename( $file );
					$dates         = explode( PHP_EOL, file_get_contents( $file ) );
					foreach ( $dates as $date ) {
						if ( strtotime( $date ) > strtotime( '12 months ago' ) ) {
							$is_new = true;
						}
						if ( strtotime( $date ) < strtotime( '12 months ago' ) ) {
							$is_new = false;
						}
					}

					if ( $is_new ) {
						$first_seen = gmdate( 'Y-m', strtotime( array_pop( $dates ) ) );
						$new_contributors[ $first_seen ][] = $contributor;
						$all_new_contribs[] = $contributor;
					}
				}
				ksort( $new_contributors );
				$data = array();
				$labels = array();
				foreach ( $new_contributors as $month => $contributors ) {
					$data[] = count( array_unique( $contributors ) );
					$labels[] = $month;
				}
				?>
				<div id="new-contributors"></div>
				<script>
				new Chartist.Line('#new-contributors', {
					labels: <?php echo json_encode( $labels ); ?>,
					series: <?php echo json_encode( array( array_values( $data ) ) ); ?>,
				}, {
					low: 0,
					onlyIntegers: true,
					showPoint: false,
				});
				</script>
			</div>
			<div class="grid-cell">
				<h3>Active Contributors (Past 30 Days)</h3>
				<?php
				$active_contributors = [];
				foreach ( glob( WP_CLI_DASHBOARD_BASE_DIR . '/github-data/contributors/*' ) as $file ) {
					$contributor = basename( $file );
					if ( in_array( $contributor, $new_contrib_users, true ) ) {
						continue;
					}
					$dates     = explode( PHP_EOL, file_get_contents( $file ) );
					$is_active = false;
					foreach ( $dates as $date ) {
						if ( strtotime( $date ) > strtotime( '30 days ago' ) ) {
							$is_active = true;
							break;
						}
					}
					if ( $is_active ) {
						$active_contributors[] = '<a href="' . sprintf( 'https://github.com/%s', $contributor ) . '" target="_blank">' . $contributor . '</a>';
					}
				}
				?>
				<p><?php echo ! empty( $active_contributors ) ? implode( ', ', $active_contributors ) : '<em>None</em>'; ?></p>
			</div>
			<div class="grid-cell" style="grid-column: span 2">
				<h3>Active Contributors (Past 12 Months)</h3>
				<?php
				$active_contributors = [];
				for ( $i = 0; $i < 12; $i++ ) {
					$active_contributors[ gmdate( 'Y-m', strtotime( '-' . $i . ' months' ) ) ] = [];
				}
				foreach ( glob( WP_CLI_DASHBOARD_BASE_DIR . '/github-data/contributors/*' ) as $file ) {
					$contributor = basename( $file );
					if ( in_array( $contributor, $all_new_contribs, true ) ) {
						continue;
					}
					$dates = explode( PHP_EOL, file_get_contents( $file ) );
					foreach ( $dates as $date ) {
						if ( strtotime( $date ) > strtotime( '12 months ago' ) ) {
							$seen = gmdate( 'Y-m', strtotime( $date ) );
							$active_contributors[ $seen ][] = $contributor;
						}
					}
				}
				ksort( $active_contributors );
				$data = array();
				$labels = array();
				foreach ( $active_contributors as $month => $contributors ) {
					$data[] = count( array_unique( $contributors ) );
					$labels[] = $month;
				}
				error_log( var_export( $data, true ) );
				error_log( var_export( $labels, true ) );
				?>
				<div id="active-contributors"></div>
				<script>
				new Chartist.Line('#active-contributors', {
					labels: <?php echo json_encode( $labels ); ?>,
					series: <?php echo json_encode( array( array_values( $data ) ) ); ?>,
				}, {
					low: 0,
					onlyIntegers: true,
					showPoint: false,
				});
				</script>
			</div>
		</div>

		<h2>Repositories</h2>

		<table>
			<thead>
				<tr>
					<th class="repository">Repository</th>
					<th>Overview</th>
					<th class="build-status">Build Status</th>
				</tr>
			</thead>
			<tbody>
			<?php
			$github_repositories = wp_cli_dashboard_get_config_data( 'github_repositories' );
			?>
			<?php
				foreach ( $github_repositories as $repo ) :
					$repo_short = str_replace( 'wp-cli/', '', $repo );
					$repo_data = json_decode( file_get_contents( WP_CLI_DASHBOARD_BASE_DIR . '/github-data/repositories/' . $repo_short . '.json' ), true );
				?>
				<tr>
					<td><a href="<?php echo sprintf( 'https://github.com/%s', $repo ); ?>" target="_blank"><?php echo $repo; ?></a></td>
					<td>
						<ul>
							<li>Project: <a href="<?php echo sprintf( 'https://github.com/%s/issues', $repo ); ?>" target="_blank"><?php echo sprintf( '%d issues', $repo_data['open_issues'] ); ?></a>, <a href="<?php echo sprintf( 'https://github.com/%s/pulls', $repo ); ?>" target="_blank"><?php echo sprintf( '%d pull requests', $repo_data['open_pull_requests'] ); ?></a></li>
							<li>
								Active:
								<?php if ( ! empty( $repo_data['active_milestone'] ) ) : ?>
									<a href="<?php echo $repo_data['active_milestone']['html_url']; ?>" target="_blank">v<?php echo $repo_data['active_milestone']['title']; ?></a> (<?php echo sprintf( '%d open', $repo_data['active_milestone']['open_issues'] ); ?>, <?php echo sprintf( '%d closed', $repo_data['active_milestone']['closed_issues'] ); ?>)
								<?php else: ?>
									<em>None</em>
								<?php endif; ?>
							</li>
							<li>
								Latest:
								<?php if ( ! empty( $repo_data['latest_release'] ) ) : ?>
									<a href="<?php echo $repo_data['latest_release']['html_url']; ?>" target="_blank"><?php echo $repo_data['latest_release']['tag_name']; ?></a>
								<?php else: ?>
									<em>None</em>
								<?php endif; ?>
							</li>
						</ul>
					</td>
					<td>
						<?php if ( 'wp-cli/wp-cli-dev' !== $repo ) : ?>
							<a href="<?php echo sprintf( 'https://github.com/%s/actions/workflows/testing.yml', $repo ); ?>" target="_blank"><img height="20px" src="<?php echo sprintf( 'https://github.com/%s/actions/workflows/testing.yml/badge.svg', $repo ); ?>" alt="Testing" style="max-width: 100%;"></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

	</main>

	<footer class="container">
	</footer>

</body>
</html>
