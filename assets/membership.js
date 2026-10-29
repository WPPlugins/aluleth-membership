/* Aluleth Membership – join card and member area forms. Works without it too (plain form posts). */
( function () {
	'use strict';
	var t = window.alulethMembershipFront || {};

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '[data-alm-signup]' ) : null;
		if ( ! btn ) {
			return;
		}
		var card = btn.closest( '.alm-card' );
		var form = card ? card.querySelector( '.alm-form--join' ) : null;
		if ( ! form ) {
			return;
		}
		e.preventDefault();
		var level = form.querySelector( '[data-alm-level]' );
		if ( level ) {
			level.value = btn.getAttribute( 'data-alm-signup' );
		}
		var name = btn.closest( '.alm-plan' ) ? btn.closest( '.alm-plan' ).querySelector( '.alm-plan__name' ) : null;
		var title = form.querySelector( '.alm-form__title' );
		if ( title && name && t.join ) {
			title.textContent = t.join.replace( '%s', name.textContent );
		}
		form.hidden = false;
		var first = form.querySelector( 'input[type=text], input[type=email]' );
		if ( first ) {
			first.focus();
		}
	} );

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( ! form.matches || ! form.matches( '[data-alm-form]' ) || ! window.fetch || ! window.FormData ) {
			return;
		}
		e.preventDefault();
		var msg = form.querySelector( '.alm-form__msg' );
		var button = form.querySelector( 'button[type=submit]' );
		if ( button ) {
			button.disabled = true;
		}
		// Pages can come from a cache: take a fresh nonce for the public forms first.
		var field = form.querySelector( 'input[data-alm-nonce]' );
		var ready = field && t.nonce
			? fetch( t.nonce, { credentials: 'same-origin', cache: 'no-store' } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( res ) {
					if ( res && res.nonce ) {
						field.value = res.nonce;
					}
				} )
				.catch( function () {} )
			: Promise.resolve();
		ready
			.then( function () {
				var data = new FormData( form );
				data.append( 'alm_ajax', '1' );
				return fetch( form.getAttribute( 'action' ), { method: 'POST', body: data, credentials: 'same-origin' } );
			} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( res ) {
				if ( msg ) {
					msg.textContent = res && res.message ? res.message : t.error;
					msg.classList.toggle( 'is-error', ! ( res && res.ok ) );
				}
				if ( res && res.ok && 'join' === form.getAttribute( 'data-alm-form' ) && ! form.querySelector( 'input[type=email]' ) ) {
					window.setTimeout( function () {
						window.location.reload();
					}, 900 );
				}
				if ( res && res.ok && form.querySelector( 'input[type=email]' ) ) {
					form.reset();
				}
			} )
			.catch( function () {
				if ( msg ) {
					msg.textContent = t.error || 'Error';
					msg.classList.add( 'is-error' );
				}
			} )
			.then( function () {
				if ( button ) {
					button.disabled = false;
				}
			} );
	} );
}() );
