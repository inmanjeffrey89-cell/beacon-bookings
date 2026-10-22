/* Beacon Bookings — admin: availability repeaters and product panel toggles. */
( function () {
	'use strict';
	var L = window.beaconBookingsRulesL10n || { days: {}, remove: 'Remove', available: 'Available', notAvailable: 'Not available' };
	var counter = 0;
	function idx() { counter++; return 'n' + Date.now().toString( 36 ) + counter; }

	function weeklyRow( prefix, day, from, to ) {
		var i = idx();
		var opts = '';
		Object.keys( L.days ).forEach( function ( d ) {
			opts += '<option value="' + d + '"' + ( String( d ) === String( day ) ? ' selected' : '' ) + '>' + L.days[ d ] + '</option>';
		} );
		return '<tr><td><select name="' + prefix + '[weekly][' + i + '][day]">' + opts + '</select></td>' +
			'<td><input type="time" step="300" name="' + prefix + '[weekly][' + i + '][from]" value="' + from + '"></td>' +
			'<td><input type="time" step="300" name="' + prefix + '[weekly][' + i + '][to]" value="' + to + '"></td>' +
			'<td><button type="button" class="button-link-delete bkb-remove">' + L.remove + '</button></td></tr>';
	}
	function overrideRow( prefix ) {
		var i = idx();
		return '<tr><td><input type="date" name="' + prefix + '[overrides][' + i + '][from]"></td>' +
			'<td><input type="date" name="' + prefix + '[overrides][' + i + '][to]"></td>' +
			'<td><select name="' + prefix + '[overrides][' + i + '][bookable]"><option value="0">' + L.notAvailable + '</option><option value="1">' + L.available + '</option></select></td>' +
			'<td><input type="time" step="300" name="' + prefix + '[overrides][' + i + '][time_from]"></td>' +
			'<td><input type="time" step="300" name="' + prefix + '[overrides][' + i + '][time_to]"></td>' +
			'<td><button type="button" class="button-link-delete bkb-remove">' + L.remove + '</button></td></tr>';
	}
	function append( table, html ) {
		var tbody = table.querySelector( 'tbody' );
		var tmp = document.createElement( 'tbody' );
		tmp.innerHTML = html;
		while ( tmp.firstChild ) { tbody.appendChild( tmp.firstChild ); }
	}

	document.addEventListener( 'click', function ( e ) {
		var t = e.target;
		if ( t.classList.contains( 'bkb-remove' ) ) {
			e.preventDefault();
			var tr = t.closest( 'tr' );
			if ( tr ) { tr.parentNode.removeChild( tr ); }
			return;
		}
		if ( t.classList.contains( 'bkb-add' ) || t.classList.contains( 'bkb-add-weekdays' ) ) {
			e.preventDefault();
			var wrap = t.closest( '.bkb-rules' );
			var prefix = wrap.getAttribute( 'data-prefix' );
			var kind = t.getAttribute( 'data-kind' );
			var table = wrap.querySelector( '.bkb-repeater[data-kind="' + kind + '"]' );
			if ( t.classList.contains( 'bkb-add-weekdays' ) ) {
				for ( var d = 1; d <= 5; d++ ) { append( table, weeklyRow( prefix, d, '09:00', '17:00' ) ); }
			} else if ( kind === 'weekly' ) {
				append( table, weeklyRow( prefix, 1, '09:00', '17:00' ) );
			} else {
				append( table, overrideRow( prefix ) );
			}
		}
	} );

	function toggles() {
		var unit = document.getElementById( '_beacon_bookings_unit' );
		var dtype = document.getElementById( '_beacon_bookings_duration_type' );
		function apply() {
			var isTime = unit && ( unit.value === 'minute' || unit.value === 'hour' );
			var isCustomer = dtype && dtype.value === 'customer';
			document.querySelectorAll( '.bkb-if-time' ).forEach( function ( n ) { n.style.display = isTime ? '' : 'none'; } );
			document.querySelectorAll( '.bkb-if-customer' ).forEach( function ( n ) { n.style.display = isCustomer ? '' : 'none'; } );
			document.querySelectorAll( '.bkb-if-overnight' ).forEach( function ( n ) { n.style.display = isTime && isCustomer ? '' : 'none'; } );
		}
		if ( unit ) { unit.addEventListener( 'change', apply ); }
		if ( dtype ) { dtype.addEventListener( 'change', apply ); }
		apply();
		var toggle = document.querySelector( '.bkb-toggle-rules' );
		if ( toggle ) {
			var box = document.querySelector( '.bkb-resource-rules' );
			var sync = function () { if ( box ) { box.style.display = toggle.checked ? '' : 'none'; } };
			toggle.addEventListener( 'change', sync );
			sync();
		}
	}
	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', toggles ); } else { toggles(); }
}() );
