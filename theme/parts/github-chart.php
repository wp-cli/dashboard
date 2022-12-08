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
	$last_sunday = gmdate( 'n/j', strtotime( 'last Sunday', strtotime( $datetime ) ) );
	if ( isset( $data[ $last_sunday ] ) ) {
		continue;
	}
	$data[ $last_sunday ] = file_get_contents( $file );
	$labels[] = $last_sunday;
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
