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
				foreach ( $github_data as $key => $metric ) :
					$link = 'https://github.com/issues?q=' . rawurlencode( $metric['search'] );
				?>
				<div class="grid-cell">
					<h3><a href="<?php echo $link; ?>" target="_blank"><?php echo $metric['label']; ?></a></h3>
					<?php echo wp_cli_dashboard_get_template_part( 'parts/github-chart', array( 'key' => $key ) ); ?>
				</div>
			<?php endforeach; ?>

		</div>

	</main>

</body>
</html>
