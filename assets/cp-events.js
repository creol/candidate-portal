/* Candidate Portal - upcoming events carousel arrows. */
( function () {
	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.cp-up-carousel' ).forEach( function ( wrap ) {
			var track = wrap.querySelector( '.cp-up-track' );
			if ( ! track ) {
				return;
			}
			var step = function () {
				var card = track.querySelector( '.cp-up-card' );
				return card ? card.getBoundingClientRect().width + 16 : 300;
			};
			var prev = wrap.querySelector( '.cp-up-prev' );
			var next = wrap.querySelector( '.cp-up-next' );
			if ( prev ) {
				prev.addEventListener( 'click', function () {
					track.scrollBy( { left: -step(), behavior: 'smooth' } );
				} );
			}
			if ( next ) {
				next.addEventListener( 'click', function () {
					track.scrollBy( { left: step(), behavior: 'smooth' } );
				} );
			}
		} );
	} );
} )();
