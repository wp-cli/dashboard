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
			<?php foreach ( $github_repositories as $repo ) : ?>
				<tr>
					<td><a href="<?php echo sprintf( 'https://github.com/%s', $repo ); ?>" target="_blank"><?php echo $repo; ?></a></td>
					<td></td>
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

</body>
</html>
