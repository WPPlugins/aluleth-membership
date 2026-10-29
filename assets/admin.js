/* Aluleth Membership – admin screens. */
( function () {
	'use strict';

	// Confirm before deleting a level, a rule or ending an access.
	document.addEventListener( 'submit', function ( e ) {
		var text = e.target.getAttribute && e.target.getAttribute( 'data-alm-confirm' );
		if ( text && ! window.confirm( text ) ) {
			e.preventDefault();
		}
	} );

	// Rule form: page picker or address field depending on "What".
	var target = document.querySelector( '[data-alm-target]' );
	function syncTarget() {
		var kind = target.value.split( '|' )[ 0 ];
		document.querySelectorAll( '[data-alm-show]' ).forEach( function ( el ) {
			el.hidden = el.getAttribute( 'data-alm-show' ) !== kind;
		} );
		document.querySelectorAll( '[data-alm-hide]' ).forEach( function ( el ) {
			el.hidden = el.getAttribute( 'data-alm-hide' ) === kind;
		} );
	}
	if ( target ) {
		target.addEventListener( 'change', syncTarget );
		syncTarget();
	}

	// Level length: the number only matters with days, months or years.
	var duration = document.querySelector( '[data-alm-duration]' );
	var count = document.querySelector( '[data-alm-duration-n]' );
	function syncDuration() {
		count.hidden = 'lifetime' === duration.value;
	}
	if ( duration && count ) {
		duration.addEventListener( 'change', syncDuration );
		syncDuration();
	}
}() );
