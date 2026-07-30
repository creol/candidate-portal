/* Candidate Portal image cropper.
 * Intercepts marked file inputs; when the user picks an image, a modal opens
 * where they can zoom, drag to recenter, and crop. The cropped result
 * replaces the file that gets uploaded. Multiple files are cropped one by one.
 * Inputs opt in via data-cp-crop="square" (1:1, candidate photos) or
 * data-cp-crop="free" (any shape, event images).
 */
( function () {
	'use strict';

	if ( typeof Cropper === 'undefined' ) {
		return; // library missing - uploads still work, just uncropped
	}

	var overlay, imgEl, cropper, queue, doneFiles, activeInput, ratioMode;

	function buildOverlay() {
		overlay = document.createElement( 'div' );
		overlay.className = 'cp-crop-overlay';
		overlay.innerHTML =
			'<div class="cp-crop-modal">' +
				'<p class="cp-crop-title">Adjust your photo - drag to recenter, scroll or pinch to zoom, drag corners to crop.</p>' +
				'<div class="cp-crop-stage"><img alt="" /></div>' +
				'<div class="cp-crop-actions">' +
					'<button type="button" class="cp-crop-use">Use this photo</button>' +
					'<button type="button" class="cp-crop-skip">Keep original</button>' +
					'<button type="button" class="cp-crop-cancel">Cancel upload</button>' +
				'</div>' +
			'</div>';
		document.body.appendChild( overlay );
		imgEl = overlay.querySelector( 'img' );

		overlay.querySelector( '.cp-crop-use' ).addEventListener( 'click', function () {
			var canvas = cropper.getCroppedCanvas( { maxWidth: 2000, maxHeight: 2000 } );
			canvas.toBlob( function ( blob ) {
				var base = ( queue.currentName || 'photo' ).replace( /\.[^.]+$/, '' );
				doneFiles.push( new File( [ blob ], base + '.jpg', { type: 'image/jpeg' } ) );
				nextInQueue();
			}, 'image/jpeg', 0.9 );
		} );
		overlay.querySelector( '.cp-crop-skip' ).addEventListener( 'click', function () {
			doneFiles.push( queue.currentFile );
			nextInQueue();
		} );
		overlay.querySelector( '.cp-crop-cancel' ).addEventListener( 'click', function () {
			teardown();
			if ( activeInput ) {
				activeInput.value = '';
			}
		} );
	}

	function teardown() {
		if ( cropper ) {
			cropper.destroy();
			cropper = null;
		}
		if ( overlay ) {
			overlay.style.display = 'none';
		}
	}

	function showCropper( file ) {
		queue.currentFile = file;
		queue.currentName = file.name;
		var reader = new FileReader();
		reader.onload = function ( e ) {
			overlay.style.display = 'flex';
			if ( cropper ) {
				cropper.destroy();
			}
			imgEl.src = e.target.result;
			cropper = new Cropper( imgEl, {
				viewMode: 1,
				aspectRatio: 'square' === ratioMode ? 1 : NaN,
				autoCropArea: 1,
				movable: true,
				zoomable: true,
				responsive: true,
				background: false
			} );
		};
		reader.readAsDataURL( file );
	}

	function nextInQueue() {
		if ( cropper ) {
			cropper.destroy();
			cropper = null;
		}
		if ( queue.files.length ) {
			showCropper( queue.files.shift() );
			return;
		}
		// All files processed: hand them back to the input.
		overlay.style.display = 'none';
		try {
			var dt = new DataTransfer();
			doneFiles.forEach( function ( f ) {
				dt.items.add( f );
			} );
			activeInput.files = dt.files;
		} catch ( err ) {
			// Very old browser: leave the original files in place.
		}
	}

	function onPick( ev ) {
		var input = ev.target;
		if ( ! input.files || ! input.files.length ) {
			return;
		}
		if ( ! overlay ) {
			buildOverlay();
		}
		activeInput = input;
		ratioMode = input.getAttribute( 'data-cp-crop' ) || 'free';
		queue = { files: Array.prototype.slice.call( input.files ) };
		doneFiles = [];
		nextInQueue();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( 'input[type="file"][data-cp-crop]' ).forEach( function ( input ) {
			input.addEventListener( 'change', onPick );
		} );
	} );
} )();
