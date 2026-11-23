( function () {
	'use strict';

	var tip = document.querySelector( '.chart-tip' );
	var fmt = new Intl.NumberFormat( 'en' );

	function escapeHtml( s ) {
		return String( s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	// ---------- Chart hover / keyboard layer ----------
	document.querySelectorAll( '.chart__plot[data-chart]' ).forEach( function ( plot ) {
		var spec = JSON.parse( plot.getAttribute( 'data-chart' ) );
		var n = spec.x.length;
		var isBar = spec.series.some( function ( s ) { return s.bar; } );
		var guide = document.createElement( 'div' );
		guide.className = isBar ? 'chart__band' : 'chart__crosshair';
		guide.hidden = true;
		plot.appendChild( guide );
		var dots = spec.series.map( function ( s ) {
			var d = document.createElement( 'span' );
			d.className = 'chart__hover-dot';
			d.style.background = 'var(' + s.color + ')';
			d.hidden = true;
			if ( ! s.bar ) {
				plot.appendChild( d );
			}
			return d;
		} );
		var current = -1;

		plot.tabIndex = 0;

		function nearest( frac ) {
			var best = 0;
			for ( var i = 1; i < n; i++ ) {
				if ( Math.abs( spec.x[ i ] - frac ) < Math.abs( spec.x[ best ] - frac ) ) {
					best = i;
				}
			}
			return best;
		}

		function show( i ) {
			current = i;
			var rect = plot.getBoundingClientRect();
			var x = spec.x[ i ];
			guide.hidden = false;
			if ( isBar ) {
				var w = 100 / n;
				guide.style.left = ( x * 100 - w / 2 ) + '%';
				guide.style.width = w + '%';
			} else {
				guide.style.left = ( x * 100 ) + '%';
			}
			var rows = '';
			spec.series.forEach( function ( s, k ) {
				var v = s.values[ i ];
				if ( ! s.bar ) {
					dots[ k ].hidden = v === null;
					if ( v !== null ) {
						dots[ k ].style.left = ( x * 100 ) + '%';
						dots[ k ].style.top = ( s.y[ i ] * 100 ) + '%';
					}
				}
				rows += '<div class="chart-tip__row"><span class="legend__swatch" style="background:var(' + s.color + ')"></span>' +
					escapeHtml( s.name ) + '<b>' + ( v === null ? 'No data' : fmt.format( v ) + ( spec.unit || '' ) ) + '</b></div>';
			} );
			tip.innerHTML = '<div class="chart-tip__title">' + escapeHtml( spec.labels[ i ] ) + '</div>' + rows;
			tip.hidden = false;
			var px = rect.left + x * rect.width;
			var tw = tip.offsetWidth;
			var left = px + 12 + tw > window.innerWidth - 8 ? px - 12 - tw : px + 12;
			tip.style.left = Math.max( 8, left ) + 'px';
			var top = rect.top - tip.offsetHeight - 8;
			tip.style.top = ( top < 8 ? rect.bottom + 8 : top ) + 'px';
		}

		function hide() {
			current = -1;
			guide.hidden = true;
			dots.forEach( function ( d ) { d.hidden = true; } );
			tip.hidden = true;
		}

		plot.addEventListener( 'pointermove', function ( e ) {
			var rect = plot.getBoundingClientRect();
			show( nearest( ( e.clientX - rect.left ) / rect.width ) );
		} );
		plot.addEventListener( 'pointerleave', hide );
		plot.addEventListener( 'blur', hide );
		plot.addEventListener( 'focus', function () { show( n - 1 ); } );
		plot.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'ArrowLeft' || e.key === 'ArrowRight' ) {
				e.preventDefault();
				var step = e.shiftKey ? 7 : 1;
				var i = current < 0 ? n - 1 : current + ( e.key === 'ArrowLeft' ? -step : step );
				show( Math.max( 0, Math.min( n - 1, i ) ) );
			} else if ( e.key === 'Escape' ) {
				hide();
			}
		} );
	} );
	window.addEventListener( 'scroll', function () { if ( tip ) { tip.hidden = true; } }, { passive: true } );

	// ---------- Sortable tables ----------
	document.querySelectorAll( 'table[data-sortable]' ).forEach( function ( table ) {
		var headers = table.querySelectorAll( 'thead th' );
		var body = table.tBodies[ 0 ];
		headers.forEach( function ( th, col ) {
			var button = th.querySelector( 'button' );
			if ( ! button ) {
				return;
			}
			button.addEventListener( 'click', function () {
				var type = button.getAttribute( 'data-sort' );
				var dir = th.getAttribute( 'aria-sort' ) === ( type === 'text' ? 'ascending' : 'descending' ) ?
					( type === 'text' ? 'descending' : 'ascending' ) :
					( type === 'text' ? 'ascending' : 'descending' );
				headers.forEach( function ( h ) { h.setAttribute( 'aria-sort', 'none' ); } );
				th.setAttribute( 'aria-sort', dir );
				var rows = Array.prototype.slice.call( body.rows );
				rows.sort( function ( a, b ) {
					var av = a.cells[ col ].getAttribute( 'data-value' );
					var bv = b.cells[ col ].getAttribute( 'data-value' );
					var r = type === 'text' ? av.localeCompare( bv ) : parseFloat( av ) - parseFloat( bv );
					if ( r === 0 ) {
						r = parseFloat( b.getAttribute( 'data-default' ) ) - parseFloat( a.getAttribute( 'data-default' ) );
						return r;
					}
					return dir === 'ascending' ? r : -r;
				} );
				rows.forEach( function ( row ) { body.appendChild( row ); } );
			} );
		} );
	} );

	// ---------- Relative timestamps ----------
	var rtf = window.Intl && Intl.RelativeTimeFormat ? new Intl.RelativeTimeFormat( 'en', { numeric: 'auto' } ) : null;
	function relative() {
		document.querySelectorAll( 'time[data-relative]' ).forEach( function ( el ) {
			if ( ! rtf ) {
				return;
			}
			var diff = ( new Date( el.getAttribute( 'datetime' ) ) - Date.now() ) / 1000;
			var abs = Math.abs( diff );
			var text = abs < 60 ? rtf.format( Math.round( diff ), 'second' ) :
				abs < 3600 ? rtf.format( Math.round( diff / 60 ), 'minute' ) :
				abs < 86400 ? rtf.format( Math.round( diff / 3600 ), 'hour' ) :
				rtf.format( Math.round( diff / 86400 ), 'day' );
			el.title = el.title || el.textContent;
			el.textContent = text;
		} );
	}
	relative();
	setInterval( relative, 60000 );
}() );
