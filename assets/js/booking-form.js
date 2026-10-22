/* Beacon Bookings — product page booking form. No dependencies. */
( function () {
	'use strict';

	function pad( n ) { return ( n < 10 ? '0' : '' ) + n; }
	function ymd( d ) { return d.getFullYear() + '-' + pad( d.getMonth() + 1 ) + '-' + pad( d.getDate() ); }
	function parseYmd( s ) { var p = s.split( '-' ); return new Date( +p[0], +p[1] - 1, +p[2] ); }
	function el( tag, cls, text ) { var e = document.createElement( tag ); if ( cls ) { e.className = cls; } if ( text !== undefined ) { e.textContent = text; } return e; }

	function BookingForm( form ) {
		var self = this;
		this.form = form;
		this.cfg = JSON.parse( form.getAttribute( 'data-bkb' ) );
		this.cal = form.querySelector( '.bkb-calendar' );
		this.slotsEl = form.querySelector( '.bkb-slots' );
		this.selectedEl = form.querySelector( '.bkb-selected' );
		this.totalEl = form.querySelector( '.bkb-total' );
		this.startInput = form.querySelector( '.bkb-start' );
		this.submit = form.querySelector( '.bkb-submit' );
		this.blocksEl = form.querySelector( '.bkb-blocks' );
		this.personsEl = form.querySelector( '.bkb-persons' );
		this.resourceEl = form.querySelector( '.bkb-resource' );
		this.cache = {};
		this.selectedDate = '';
		this.selectedSlot = null;
		var today = parseYmd( this.cfg.today );
		this.year = today.getFullYear();
		this.month = today.getMonth();
		this.monthFmt = new Intl.DateTimeFormat( this.cfg.locale, { month: 'long', year: 'numeric' } );
		this.dayFmt = new Intl.DateTimeFormat( this.cfg.locale, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' } );

		[ this.blocksEl, this.personsEl, this.resourceEl ].forEach( function ( c ) {
			if ( c ) { c.addEventListener( 'change', function () { self.onControlsChange(); } ); }
		} );
		form.addEventListener( 'submit', function ( e ) {
			if ( ! self.startInput.value ) { e.preventDefault(); }
		} );
		if ( this.cfg.preselect ) {
			var p = parseYmd( this.cfg.preselect );
			this.year = p.getFullYear();
			this.month = p.getMonth();
		}
		this.loadMonth();
	}

	BookingForm.prototype.params = function () {
		var q = [ 'product=' + this.cfg.product ];
		if ( this.blocksEl ) { q.push( 'blocks=' + encodeURIComponent( this.blocksEl.value ) ); }
		if ( this.personsEl ) { q.push( 'persons=' + encodeURIComponent( this.personsEl.value || this.cfg.persons.min ) ); }
		if ( this.resourceEl && this.resourceEl.value ) { q.push( 'resource=' + encodeURIComponent( this.resourceEl.value ) ); }
		return q.join( '&' );
	};

	BookingForm.prototype.blocks = function () { return this.blocksEl ? parseInt( this.blocksEl.value, 10 ) || 1 : 1; };

	BookingForm.prototype.onControlsChange = function () {
		this.cache = {};
		this.clearSelection();
		this.loadMonth();
	};

	BookingForm.prototype.clearSelection = function () {
		this.selectedDate = '';
		this.selectedSlot = null;
		this.startInput.value = '';
		this.selectedEl.textContent = '';
		this.totalEl.textContent = '';
		this.slotsEl.innerHTML = '';
		this.submit.disabled = true;
	};

	BookingForm.prototype.fetchJson = function ( path, extra, cb ) {
		var self = this;
		var url = this.cfg.restUrl + path + ( this.cfg.restUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + this.params() + ( extra ? '&' + extra : '' );
		fetch( url, { credentials: 'same-origin' } ).then( function ( r ) { return r.json(); } ).then( cb ).catch( function () {
			self.cal.innerHTML = '';
			self.cal.appendChild( el( 'p', 'bkb-note bkb-error', self.cfg.i18n.error ) );
		} );
	};

	BookingForm.prototype.loadMonth = function () {
		var self = this;
		var key = this.year + '-' + pad( this.month + 1 );
		if ( this.cache[ key ] ) { this.render( this.cache[ key ] ); return; }
		var first = new Date( this.year, this.month, 1 );
		var last = new Date( this.year, this.month + 1, 0 );
		this.cal.innerHTML = '';
		this.cal.appendChild( el( 'p', 'bkb-note', this.cfg.i18n.loading ) );
		this.fetchJson( 'availability', 'from=' + ymd( first ) + '&to=' + ymd( last ), function ( data ) {
			if ( ! data || ! data.days ) { self.cal.innerHTML = ''; self.cal.appendChild( el( 'p', 'bkb-note bkb-error', self.cfg.i18n.error ) ); return; }
			self.cache[ key ] = data.days;
			self.render( data.days );
			if ( self.cfg.preselect && self.cache[ key ][ self.cfg.preselect ] ) {
				var pre = self.cfg.preselect; self.cfg.preselect = '';
				if ( self.cache[ key ][ pre ].available ) { self.selectDate( pre ); }
			}
		} );
	};

	BookingForm.prototype.render = function ( days ) {
		var self = this, i;
		var cfg = this.cfg;
		this.cal.innerHTML = '';
		var head = el( 'div', 'bkb-cal-head' );
		var prev = el( 'button', 'bkb-nav bkb-prev', '‹' ); prev.type = 'button'; prev.setAttribute( 'aria-label', cfg.i18n.prev );
		var next = el( 'button', 'bkb-nav bkb-next', '›' ); next.type = 'button'; next.setAttribute( 'aria-label', cfg.i18n.next );
		var title = el( 'div', 'bkb-cal-title', this.monthFmt.format( new Date( this.year, this.month, 1 ) ) );
		var today = parseYmd( cfg.today );
		var minMonth = today.getFullYear() * 12 + today.getMonth();
		var thisMonth = this.year * 12 + this.month;
		prev.disabled = thisMonth <= minMonth;
		next.disabled = thisMonth >= minMonth + cfg.months;
		prev.addEventListener( 'click', function () { self.month--; if ( self.month < 0 ) { self.month = 11; self.year--; } self.loadMonth(); } );
		next.addEventListener( 'click', function () { self.month++; if ( self.month > 11 ) { self.month = 0; self.year++; } self.loadMonth(); } );
		head.appendChild( prev ); head.appendChild( title ); head.appendChild( next );
		this.cal.appendChild( head );

		var grid = el( 'div', 'bkb-cal-grid' );
		grid.setAttribute( 'role', 'grid' );
		for ( i = 0; i < 7; i++ ) {
			grid.appendChild( el( 'div', 'bkb-wd', cfg.weekdays[ ( cfg.firstDay + i ) % 7 ] ) );
		}
		var first = new Date( this.year, this.month, 1 );
		var lead = ( first.getDay() - cfg.firstDay + 7 ) % 7;
		for ( i = 0; i < lead; i++ ) { grid.appendChild( el( 'div', 'bkb-day bkb-empty' ) ); }
		var count = new Date( this.year, this.month + 1, 0 ).getDate();
		for ( i = 1; i <= count; i++ ) {
			var d = new Date( this.year, this.month, i );
			var k = ymd( d );
			var info = days[ k ];
			var avail = !! ( info && info.available );
			var b = el( 'button', 'bkb-day' + ( avail ? ' bkb-available' : ' bkb-unavailable' ) + ( k === cfg.today ? ' bkb-today' : '' ) + ( k === this.selectedDate ? ' bkb-selected' : '' ), String( i ) );
			b.type = 'button';
			b.disabled = ! avail;
			b.setAttribute( 'data-date', k );
			b.setAttribute( 'aria-label', this.dayFmt.format( d ) + ', ' + ( avail ? cfg.i18n.available : cfg.i18n.unavailable ) );
			b.setAttribute( 'aria-pressed', k === this.selectedDate ? 'true' : 'false' );
			if ( avail && cfg.isDay && info.remaining > 0 && info.remaining <= 3 ) {
				b.appendChild( el( 'span', 'bkb-left', cfg.i18n.left.replace( '%d', info.remaining ) ) );
			}
			b.addEventListener( 'click', function ( ev ) { self.selectDate( ev.currentTarget.getAttribute( 'data-date' ) ); } );
			grid.appendChild( b );
		}
		this.cal.appendChild( grid );
		if ( ! cfg.isDay && ! this.selectedDate ) {
			this.slotsEl.innerHTML = '';
			this.slotsEl.appendChild( el( 'p', 'bkb-note', cfg.i18n.pickDay ) );
		}
	};

	BookingForm.prototype.selectDate = function ( date ) {
		var self = this;
		var key = date.substr( 0, 7 );
		var days = this.cache[ key ] || {};
		var info = days[ date ];
		if ( ! info || ! info.available ) { return; }
		this.selectedDate = date;
		this.selectedSlot = null;
		this.cal.querySelectorAll( '.bkb-day' ).forEach( function ( b ) {
			var on = b.getAttribute( 'data-date' ) === date;
			b.classList.toggle( 'bkb-selected', on );
			b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		} );
		if ( this.cfg.isDay ) {
			this.startInput.value = date;
			var start = parseYmd( date );
			var label = this.dayFmt.format( start );
			var nights = this.blocks() * ( this.cfg.durationDays || 1 );
			if ( nights > 1 || this.cfg.unit === 'night' ) {
				var end = new Date( start.getTime() );
				end.setDate( end.getDate() + nights );
				label += ' → ' + this.dayFmt.format( end ) + ( this.cfg.unit === 'night' ? ' (' + this.cfg.i18n.checkout + ')' : '' );
			}
			this.selectedEl.textContent = label;
			this.submit.disabled = false;
			this.loadPrice();
			return;
		}
		this.startInput.value = '';
		this.submit.disabled = true;
		this.selectedEl.textContent = '';
		this.totalEl.textContent = '';
		this.slotsEl.innerHTML = '';
		var slots = info.slots || [];
		if ( ! slots.length ) { this.slotsEl.appendChild( el( 'p', 'bkb-note', this.cfg.i18n.noSlots ) ); return; }
		var list = el( 'div', 'bkb-slot-list' );
		list.setAttribute( 'role', 'group' );
		slots.forEach( function ( s ) {
			var b = el( 'button', 'bkb-slot' + ( s.available ? '' : ' bkb-unavailable' ), s.start + ' – ' + self.endLabel( s ) );
			b.type = 'button';
			b.disabled = ! s.available;
			b.setAttribute( 'aria-pressed', 'false' );
			if ( s.available && s.remaining > 0 && s.remaining <= 3 ) { b.appendChild( el( 'span', 'bkb-left', self.cfg.i18n.left.replace( '%d', s.remaining ) ) ); }
			b.addEventListener( 'click', function () { self.selectSlot( s, b ); } );
			list.appendChild( b );
		} );
		this.slotsEl.appendChild( list );
	};

	/** "13:00", or "Fri, Oct 2, 13:00" when an overnight rental comes back on a later day. */
	BookingForm.prototype.endLabel = function ( s ) {
		return s.end_date && s.end_date !== this.selectedDate ? this.dayFmt.format( parseYmd( s.end_date ) ) + ', ' + s.end : s.end;
	};

	BookingForm.prototype.selectSlot = function ( slot, btn ) {
		this.selectedSlot = slot;
		this.slotsEl.querySelectorAll( '.bkb-slot' ).forEach( function ( b ) { b.classList.remove( 'bkb-selected' ); b.setAttribute( 'aria-pressed', 'false' ); } );
		btn.classList.add( 'bkb-selected' );
		btn.setAttribute( 'aria-pressed', 'true' );
		this.startInput.value = this.selectedDate + ' ' + slot.start;
		this.selectedEl.textContent = this.dayFmt.format( parseYmd( this.selectedDate ) ) + ', ' + slot.start + ' – ' + this.endLabel( slot );
		this.submit.disabled = false;
		this.loadPrice();
	};

	BookingForm.prototype.loadPrice = function () {
		var self = this;
		this.fetchJson( 'price', 'start=' + encodeURIComponent( this.startInput.value ), function ( data ) {
			if ( data && data.html ) {
				self.totalEl.innerHTML = '';
				self.totalEl.appendChild( el( 'span', 'bkb-total-label', self.cfg.i18n.total + ': ' ) );
				var v = el( 'span', 'bkb-total-value' );
				v.innerHTML = data.html;
				self.totalEl.appendChild( v );
			}
		} );
	};

	function init() {
		document.querySelectorAll( 'form.bkb-form' ).forEach( function ( f ) {
			if ( ! f.bkbInit ) { f.bkbInit = new BookingForm( f ); }
		} );
	}
	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', init ); } else { init(); }
}() );
