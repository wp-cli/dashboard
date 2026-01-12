<?php
/**
 * Generates a chart for a given bit of GitHub data.
 *
 * @param string $key Key for the GitHub data.
 */
if ( empty( $key ) ) {
	return;
}

$data = array();
for ( $i = 0; $i < 12; $i++ ) {
	$month = gmdate( 'Y-m', strtotime( '-' . $i . ' months' ) );
	$data[ $month ] = 0;
}

foreach ( glob( WP_CLI_DASHBOARD_BASE_DIR . '/github-data/' . $key . '/*' ) as $file ) {
	$datetime = basename( $file );
	$timestamp = strtotime( $datetime );
	if ( $timestamp > strtotime( '12 months ago' ) ) {
		$month = gmdate( 'Y-m', $timestamp );
		$data[ $month ] = (int) file_get_contents( $file );
	}
}

ksort( $data );

$labels = array_keys( $data );
$series = array_values( $data );

?>
<canvas id="<?php echo $key; ?>" class="ct-chart"></canvas>
<script>
new Chart(document.getElementById('<?php echo $key; ?>'), {
	type: 'line',
	data: {
		labels: <?php echo json_encode( $labels ); ?>,
		datasets: [{
			data: <?php echo json_encode( $series ); ?>,
			label: 'Count',
			borderColor: '#2ecc71',
			fill: false
		}]
	},
	options: {
		plugins: {
			tooltip: {
				mode: 'index',
				intersect: false
			},
			legend: {
				display: false
			}
		},
		scales: {
			x: {
				title: {
					display: true,
					text: 'Time (months)'
				}
			},
			y: {
				title: {
					display: true,
					text: 'Count'
				},
				beginAtZero: true,
				ticks: {
					stepSize: 1
				}
			}
		}
	}
});
</script>
