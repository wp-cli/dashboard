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
$labels = array();
foreach ( glob( WP_CLI_DASHBOARD_BASE_DIR . '/github-data/' . $key . '/*' ) as $file ) {
	$datetime = basename( $file );
	$timestamp = strtotime( $datetime );
	if ( strtotime( gmdate( 'Y-m-d 00:00:00', $timestamp ) ) > strtotime( '3 days ago', strtotime( gmdate( 'Y-m-d 00:00:00' ) ) ) ) {
		$time_period = gmdate( 'n/j', $timestamp );
	} else {
		$time_period = gmdate( 'n/j', strtotime( 'last Sunday', strtotime( $datetime ) ) );
	}
	if ( isset( $data[ $time_period ] ) ) {
		continue;
	}
	$data[ $time_period ] = file_get_contents( $file );
	$labels[] = $time_period;
}

?>
<div id="<?php echo $key; ?>"></div>
<script>
new Chartist.Line('#<?php echo $key; ?>', {
	labels: <?php echo json_encode( $labels ); ?>,
	series: <?php echo json_encode( array( array_values( $data ) ) ); ?>,
}, {
	low: 0,
	onlyIntegers: true,
	showPoint: false,
});
</script>
