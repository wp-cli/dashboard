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
							<li>Project: <a href="<?php echo sprintf( 'https://github.com/%s/issues', $repo ); ?>" target="_blank"><?php echo sprintf( '%d issues', $repo_data['open_issues'] ); ?></a>, <a href="<?php echo sprintf( 'https://github.com/%s/issues', $repo ); ?>" target="_blank"><?php echo sprintf( '%d pull requests', $repo_data['open_pull_requests'] ); ?></a></li>
							<li>
								Active:
								<?php if ( ! empty( $repo_data['active_milestone'] ) ) : ?>
									<a href="<?php echo $repo_data['active_milestone']['html_url']; ?>" target="_blank">v<?php echo $repo_data['active_milestone']['title']; ?></a> (<?php echo sprintf( '%d open', $repo_data['active_milestone']['open_issues'] ); ?>, <?php echo sprintf( '%d open', $repo_data['active_milestone']['closed_issues'] ); ?>)
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
