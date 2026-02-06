<?php
/**
 * Generates a chart for activity data (list of timestamps).
 *
 * @param array  $datasets Array of datasets. Each dataset is an array with 'label', 'color', and 'data' (array of date strings).
 * @param string $id       Unique ID for the canvas.
 */

if ( empty( $datasets ) || ! is_array( $datasets ) ) {
	return;
}

// Prepare buckets (last 52 weeks)
$weeks_template = array();
// Start from 1 year ago, aligned to week start
$start_time = strtotime( '1 year ago' );
$current = $start_time;
$end_time = time();

while ( $current <= $end_time ) {
	$key = date( 'Y-W', $current );
	$weeks_template[ $key ] = 0;
	$current = strtotime( '+1 week', $current );
}

$chart_datasets = array();

foreach ( $datasets as $dataset ) {
	if ( empty( $dataset['data'] ) ) {
		$dataset['data'] = array();
	}
	
	$weeks = $weeks_template;

	// Aggregate
	foreach ( $dataset['data'] as $date ) {
		$timestamp = strtotime( $date );
		if ( $timestamp < $start_time ) {
			continue;
		}
		$key = date( 'Y-W', $timestamp );
		if ( isset( $weeks[ $key ] ) ) {
			$weeks[ $key ]++;
		}
	}

	$chart_datasets[] = array(
		'label'           => $dataset['label'],
		'backgroundColor' => $dataset['color'],
		'data'            => array_values( $weeks ),
	);
}

$labels = array_keys( $weeks_template );

?>
<canvas id="<?php echo $id; ?>"></canvas>
<script>
new Chart(document.getElementById('<?php echo $id; ?>'), {
	type: 'bar',
	data: {
		labels: <?php echo json_encode( $labels ); ?>,
		datasets: <?php echo json_encode( $chart_datasets ); ?>
	},
	options: {
		interaction: {
			mode: 'index',
			intersect: false,
		},
		plugins: {
			tooltip: {
				mode: 'index',
				intersect: false
			},
			legend: {
				display: true
			}
		},
		scales: {
			x: {
				display: false 
			},
			y: {
				beginAtZero: true,
				ticks: {
					stepSize: 1
				}
			}
		}
	}
});
</script>
