<?php
/**
 * Server-rendered SVG charts.
 *
 * Marks are drawn in an SVG stretched to the plot box (preserveAspectRatio
 * "none" with non-scaling strokes); axis labels, markers and tooltips are
 * HTML positioned in percentages, so text stays legible at any width and
 * the page needs no chart library. Hover behavior lives in theme/assets/charts.js.
 */

/**
 * Rounds a step to 1, 2 or 5 × 10^n.
 *
 * @param float $raw
 * @return float
 */
function wp_cli_dashboard_nice_step( $raw ) {
	if ( $raw <= 0 ) {
		return 1;
	}
	$power = pow( 10, floor( log10( $raw ) ) );
	foreach ( array( 1, 2, 5, 10 ) as $m ) {
		if ( $raw <= $m * $power ) {
			return max( 1, $m * $power );
		}
	}
	return 10 * $power;
}

/**
 * Renders a chart.
 *
 * @param array $args {
 *     @type string $type   'line', 'bars' (diverging when a series has sign -1) or 'spark'.
 *     @type array  $labels Tooltip label for each point.
 *     @type array  $ticks  X-axis labels: list of [ index, text, minor? ].
 *     @type array  $series List of { name, color (CSS custom property), values, sign? }.
 *     @type int    $height Plot height in px.
 *     @type string $label  Accessible description.
 *     @type string $unit   Value suffix in tooltips.
 * }
 * @return string HTML.
 */
function wp_cli_dashboard_chart( $args ) {
	$args = array_merge(
		array(
			'type'   => 'line',
			'labels' => array(),
			'ticks'  => array(),
			'series' => array(),
			'height' => 200,
			'label'  => '',
			'unit'   => '',
		),
		$args
	);

	$type   = $args['type'];
	$height = (int) $args['height'];
	$n      = count( $args['labels'] );
	$spark  = 'spark' === $type;
	if ( 0 === $n ) {
		return '';
	}

	// Domain.
	$up   = 0;
	$down = 0;
	$min  = null;
	foreach ( $args['series'] as $s ) {
		foreach ( $s['values'] as $v ) {
			if ( null === $v ) {
				continue;
			}
			if ( isset( $s['sign'] ) && -1 === $s['sign'] ) {
				$down = max( $down, $v );
			} else {
				$up  = max( $up, $v );
				$min = null === $min ? $v : min( $min, $v );
			}
		}
	}
	$ticks_y = array();
	if ( $spark ) {
		// Sparklines zoom to their own range so the trend reads.
		$lo = null === $min ? 0 : $min;
		$hi = max( $up, $lo + 1 );
		$pad = ( $hi - $lo ) * 0.12;
		$lo -= $pad;
		$hi += $pad;
	} else {
		$step = wp_cli_dashboard_nice_step( ( $up + $down ) / ( $down > 0 ? 5 : 4 ) );
		$hi   = max( $step, ceil( $up / $step ) * $step );
		$lo   = -ceil( $down / $step ) * $step;
		for ( $t = $lo; $t <= $hi + 0.0001; $t += $step ) {
			$ticks_y[] = $t;
		}
	}
	$yf = function ( $v ) use ( $lo, $hi ) {
		return ( $hi - $v ) / ( $hi - $lo );
	};
	$xf = function ( $i ) use ( $n, $type ) {
		if ( 'bars' === $type ) {
			return ( $i + 0.5 ) / $n;
		}
		return $n > 1 ? $i / ( $n - 1 ) : 0.5;
	};

	$W   = 1000;
	$svg = array();
	foreach ( $ticks_y as $t ) {
		$y     = round( $yf( $t ) * $height, 2 );
		$class = 0.0 === (float) $t ? 'baseline' : 'gridline';
		$svg[] = sprintf( '<line class="%s" x1="0" x2="%d" y1="%s" y2="%s" vector-effect="non-scaling-stroke"/>', $class, $W, $y, $y );
	}

	$tip_series = array();
	foreach ( $args['series'] as $s ) {
		$sign  = isset( $s['sign'] ) ? (int) $s['sign'] : 1;
		$color = $s['color'];
		$ys    = array();
		if ( 'bars' === $type ) {
			$band = $W / $n;
			$bw   = max( 1, $band * 0.62 );
			$d    = '';
			foreach ( $s['values'] as $i => $v ) {
				$ys[] = null === $v ? null : round( $yf( $sign * $v ), 4 );
				if ( ! $v ) {
					continue;
				}
				$x0 = $xf( $i ) * $W - $bw / 2;
				$y0 = $yf( 0 ) * $height;
				$y1 = $yf( $sign * $v ) * $height;
				$d .= sprintf( 'M%.2f %.2fH%.2fV%.2fH%.2fZ', $x0, $y0, $x0 + $bw, $y1, $x0 );
			}
			$svg[] = sprintf( '<path d="%s" style="fill:var(%s)"/>', $d, $color );
		} else {
			$d    = '';
			$pen  = false;
			foreach ( $s['values'] as $i => $v ) {
				if ( null === $v ) {
					$ys[] = null;
					$pen  = false;
					continue;
				}
				$y    = $yf( $v );
				$ys[] = round( $y, 4 );
				$d   .= sprintf( '%s%.2f %.2f', $pen ? 'L' : 'M', $xf( $i ) * $W, $y * $height );
				$pen  = true;
			}
			$svg[] = sprintf( '<path d="%s" class="line" style="stroke:var(%s)" vector-effect="non-scaling-stroke"/>', $d, $color );
		}
		$tip_series[] = array(
			'name'   => $s['name'],
			'color'  => $color,
			'values' => array_values( $s['values'] ),
			'y'      => $ys,
			'bar'    => 'bars' === $type,
		);
	}

	$xs = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$xs[] = round( $xf( $i ), 4 );
	}
	$tip = array(
		'x'      => $xs,
		'labels' => array_values( $args['labels'] ),
		'series' => $tip_series,
		'unit'   => $args['unit'],
	);

	ob_start();
	?>
	<div class="chart chart--<?php echo $type; ?>" style="--plot-h: <?php echo $height; ?>px">
		<?php if ( ! $spark ) : ?>
		<div class="chart__y" aria-hidden="true">
			<?php foreach ( $ticks_y as $t ) : ?>
				<span style="top: <?php echo round( $yf( $t ) * 100, 3 ); ?>%"><?php echo number_format( abs( $t ) ); ?></span>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
		<div class="chart__plot" role="img" aria-label="<?php echo wp_cli_dashboard_esc( $args['label'] ); ?>" data-chart="<?php echo wp_cli_dashboard_esc( json_encode( $tip ) ); ?>">
			<svg viewBox="0 0 <?php echo $W; ?> <?php echo $height; ?>" preserveAspectRatio="none" aria-hidden="true"><?php echo implode( '', $svg ); ?></svg>
			<?php
			if ( $spark ) :
				// Mark the latest value.
				foreach ( $tip_series as $s ) {
					for ( $i = $n - 1; $i >= 0; $i-- ) {
						if ( null !== $s['y'][ $i ] ) {
							printf( '<span class="chart__dot" style="left:%s%%;top:%s%%;background:var(%s)"></span>', $xs[ $i ] * 100, $s['y'][ $i ] * 100, $s['color'] );
							break;
						}
					}
				}
			endif;
			?>
		</div>
		<?php if ( ! empty( $args['ticks'] ) ) : ?>
		<div class="chart__x" aria-hidden="true">
			<?php foreach ( $args['ticks'] as $tick ) : ?>
				<span class="<?php echo ! empty( $tick[2] ) ? 'minor' : ''; ?>" style="left: <?php echo round( $xs[ $tick[0] ] * 100, 3 ); ?>%"><?php echo wp_cli_dashboard_esc( $tick[1] ); ?></span>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Renders a legend.
 *
 * @param array $items List of [ name, color (CSS custom property), value? ].
 * @return string
 */
function wp_cli_dashboard_legend( $items ) {
	$html = '<ul class="legend">';
	foreach ( $items as $item ) {
		$html .= sprintf(
			'<li><span class="legend__swatch" style="background:var(%s)"></span>%s%s</li>',
			$item[1],
			wp_cli_dashboard_esc( $item[0] ),
			isset( $item[2] ) ? ' <strong class="num">' . wp_cli_dashboard_esc( $item[2] ) . '</strong>' : ''
		);
	}
	return $html . '</ul>';
}

/**
 * Renders a collapsible data table for a chart.
 *
 * @param array $labels Row labels.
 * @param array $series List of { name, values }.
 * @return string
 */
function wp_cli_dashboard_chart_table( $labels, $series, $first = 'Period' ) {
	$html  = '<details class="chart-table"><summary>View data</summary><div class="table-scroll"><table><thead><tr><th scope="col">' . wp_cli_dashboard_esc( $first ) . '</th>';
	foreach ( $series as $s ) {
		$html .= '<th scope="col" class="num">' . wp_cli_dashboard_esc( $s['name'] ) . '</th>';
	}
	$html .= '</tr></thead><tbody>';
	foreach ( array_values( $labels ) as $i => $label ) {
		$html .= '<tr><th scope="row">' . wp_cli_dashboard_esc( $label ) . '</th>';
		foreach ( $series as $s ) {
			$v     = array_values( $s['values'] )[ $i ];
			$html .= '<td class="num">' . ( null === $v ? '–' : number_format( $v ) ) . '</td>';
		}
		$html .= '</tr>';
	}
	return $html . '</tbody></table></div></details>';
}
