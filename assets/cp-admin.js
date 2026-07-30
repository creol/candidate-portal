/* Candidate Portal admin: alphabet quick-entry box. */
( function () {
	document.addEventListener( 'DOMContentLoaded', function () {
		var box = document.getElementById( 'cp-quick-alphabet' );
		var status = document.getElementById( 'cp-quick-status' );
		if ( ! box ) {
			return;
		}

		box.addEventListener( 'input', function () {
			// Keep only letters, uppercase, first occurrence of each.
			var raw = box.value.toUpperCase().replace( /[^A-Z]/g, '' );
			var seen = {};
			var letters = [];
			for ( var i = 0; i < raw.length; i++ ) {
				var ch = raw[ i ];
				if ( ! seen[ ch ] ) {
					seen[ ch ] = true;
					letters.push( ch );
				}
			}
			if ( letters.length > 26 ) {
				letters = letters.slice( 0, 26 );
			}

			// Re-format the box with dashes between letters.
			box.value = letters.join( '-' );

			// Fill the number boxes: position in the typed order = value.
			for ( var p = 0; p < letters.length; p++ ) {
				var input = document.querySelector( 'input[name="letter_' + letters[ p ] + '"]' );
				if ( input ) {
					input.value = p + 1;
					input.classList.add( 'cp-filled' );
				}
			}

			if ( status ) {
				if ( letters.length === 26 ) {
					status.textContent = 'All 26 letters entered - numbers below are filled in. Verify and save.';
					status.style.color = '#2f855a';
				} else if ( letters.length > 0 ) {
					var missing = [];
					'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split( '' ).forEach( function ( l ) {
						if ( ! seen[ l ] ) {
							missing.push( l );
						}
					} );
					status.textContent = letters.length + ' of 26 letters. Still missing: ' + missing.join( ' ' );
					status.style.color = '';
				} else {
					status.textContent = 'Typed letters fill in the number boxes below.';
					status.style.color = '';
				}
			}
		} );
	} );
} )();

/* Candidate search filter on the election edit screen. */
( function () {
	document.addEventListener( 'DOMContentLoaded', function () {
		var search = document.getElementById( 'cp-candidate-search' );
		if ( ! search ) {
			return;
		}
		search.addEventListener( 'input', function () {
			var q = search.value.toLowerCase().trim();
			document.querySelectorAll( '.cp-candidate-picker .cp-pick' ).forEach( function ( el ) {
				el.style.display = ( ! q || el.getAttribute( 'data-name' ).indexOf( q ) !== -1 ) ? '' : 'none';
			} );
		} );
	} );
} )();
