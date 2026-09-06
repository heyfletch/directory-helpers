/**
 * Placeholder fitter for the instant-search input.
 *
 * The input renders with the longest placeholder wording; this picks the longest of its variants
 * (placeholder, data-ph-1, data-ph-2) that actually fits the input's own content box, and if even
 * the shortest would clip it tightens letter-spacing a step at a time. Font size is never touched -
 * anything under 16px makes iOS Safari zoom the page on focus.
 *
 * Deliberately its own file with a plain name and handle: the host delays every script whose URL or
 * body matches "instant-search", and this one has to run on load.
 */
( function () {
	var ctx = document.createElement( 'canvas' ).getContext( '2d' );
	var TIGHTEN = [ 0, -0.012, -0.024 ]; // extra em subtracted from the input's own letter-spacing
	var full = new WeakMap();
	var queued = false;

	function widthOf( text, font, letterSpacing ) {
		ctx.font = font;
		return ctx.measureText( text ).width + letterSpacing * text.length;
	}

	function fit( input ) {
		if ( ! full.has( input ) ) { full.set( input, input.placeholder || '' ); }
		var variants = [ full.get( input ), input.getAttribute( 'data-ph-1' ), input.getAttribute( 'data-ph-2' ) ]
			.filter( function ( v ) { return v; } );
		if ( ! variants.length ) { return; }
		input.style.letterSpacing = '';
		var cs = getComputedStyle( input );
		var box = input.clientWidth - parseFloat( cs.paddingLeft ) - parseFloat( cs.paddingRight );
		if ( ! ( box > 0 ) ) { return; }
		var font = cs.font || ( cs.fontStyle + ' ' + cs.fontWeight + ' ' + cs.fontSize + '/' + cs.lineHeight + ' ' + cs.fontFamily );
		var size = parseFloat( cs.fontSize );
		var base = parseFloat( cs.letterSpacing ) || 0;
		for ( var t = 0; t < TIGHTEN.length; t++ ) {
			var ls = base + TIGHTEN[ t ] * size;
			for ( var v = 0; v < variants.length; v++ ) {
				if ( widthOf( variants[ v ], font, ls ) <= box - 1 ) {
					input.placeholder = variants[ v ];
					input.style.letterSpacing = t ? ls.toFixed( 3 ) + 'px' : '';
					return;
				}
			}
		}
		// nothing fits even tightened: shortest wording, tightest tracking
		input.placeholder = variants[ variants.length - 1 ];
		input.style.letterSpacing = ( base + TIGHTEN[ TIGHTEN.length - 1 ] * size ).toFixed( 3 ) + 'px';
	}

	function run() {
		queued = false;
		var inputs = document.querySelectorAll( 'input.dhis-input[data-ph-1]' );
		for ( var i = 0; i < inputs.length; i++ ) { fit( inputs[ i ] ); }
	}

	function schedule() {
		if ( queued ) { return; }
		queued = true;
		requestAnimationFrame( run );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', schedule );
	} else {
		schedule();
	}
	if ( document.fonts && document.fonts.ready ) { document.fonts.ready.then( schedule ); }
	window.addEventListener( 'resize', schedule );
	window.addEventListener( 'orientationchange', schedule );
	window.addEventListener( 'load', schedule );
}() );
