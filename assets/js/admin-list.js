/* Bookings list: routes the list table's bulk action through the nonce-protected admin-post form. */
( function () {
	var list = document.querySelector( '.wrap form[method="get"]' ), bulk = document.getElementById( 'beacon-bookings-bulk-form' );
	if ( ! list || ! bulk ) { return; }
	list.addEventListener( 'submit', function ( e ) {
		var sel = list.querySelector( 'select[name="action"]' ), sel2 = list.querySelector( 'select[name="action2"]' );
		var act = sel && sel.value !== '-1' ? sel.value : ( sel2 && sel2.value !== '-1' ? sel2.value : '' );
		if ( ! act ) { return; }
		e.preventDefault();
		bulk.querySelectorAll( '.bkb-id' ).forEach( function ( n ) { n.remove(); } );
		var d = document.createElement( 'input' ); d.type = 'hidden'; d.name = 'do'; d.value = act; d.className = 'beacon-bookings-id'; bulk.appendChild( d );
		list.querySelectorAll( 'input[name="ids[]"]:checked' ).forEach( function ( c ) {
			var i = document.createElement( 'input' ); i.type = 'hidden'; i.name = 'ids[]'; i.value = c.value; i.className = 'beacon-bookings-id'; bulk.appendChild( i );
		} );
		bulk.submit();
	} );
}() );
