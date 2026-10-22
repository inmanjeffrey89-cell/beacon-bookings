/* Beacon Bookings — Booking Calendar block (editor). */
( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var RangeControl = wp.components.RangeControl;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'beacon-bookings/calendar', {
		edit: function ( props ) {
			var a = props.attributes;
			return el( wp.element.Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Calendar', 'beacon-bookings' ), initialOpen: true },
						el( TextControl, {
							label: __( 'Product IDs (comma-separated, empty = all bookable products)', 'beacon-bookings' ),
							value: a.products,
							onChange: function ( v ) { props.setAttributes( { products: v } ); }
						} ),
						el( RangeControl, {
							label: __( 'Months to show', 'beacon-bookings' ),
							min: 1, max: 3, value: a.months,
							onChange: function ( v ) { props.setAttributes( { months: v } ); }
						} )
					)
				),
				el( 'div', wp.blockEditor.useBlockProps(),
					el( ServerSideRender, { block: 'beacon-bookings/calendar', attributes: a } )
				)
			);
		},
		save: function () { return null; }
	} );
}( window.wp ) );
