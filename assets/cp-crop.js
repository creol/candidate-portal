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

	var overlay, imgEl, cropper, queue, doneFiles, activeInput, ratioMode, editCb;

	function buildOverlay() {
		overlay = document.createElement( 'div' );
		overlay.className = 'cp-crop-overlay';
		overlay.innerHTML =
			'<div class="cp-crop-modal">' +
				'<p class="cp-crop-title">Adjust your photo - drag to recenter, scroll or pinch to zoom, drag corners to crop.</p>' +
				'<div class="cp-crop-stage"><img alt="" /></div>' +
				'<div class="cp-crop-actions">' +
					'<button type="button" class="cp-crop-use">Use this photo</button>' +
					'<button type="button" class="cp-crop-fit">Fit to 1024\u00d7576 (pad edges)</button>' +
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
				var file = new File( [ blob ], base + '.jpg', { type: 'image/jpeg' } );
				if ( editCb ) {
					var cb = editCb;
					editCb = null;
					teardown();
					cb( file );
					return;
				}
				doneFiles.push( file );
				nextInQueue();
			}, 'image/jpeg', 0.9 );
		} );
		overlay.querySelector( '.cp-crop-fit' ).addEventListener( 'click', function () {
			// Scale the current selection to fit inside 1024x576 and pad the
			// remaining space evenly with white - nothing gets cropped away.
			var src = cropper.getCroppedCanvas( { maxWidth: 4000, maxHeight: 4000 } );
			var target = document.createElement( 'canvas' );
			target.width = 1024;
			target.height = 576;
			var ctx = target.getContext( '2d' );
			ctx.fillStyle = '#ffffff';
			ctx.fillRect( 0, 0, target.width, target.height );
			var scale = Math.min( target.width / src.width, target.height / src.height );
			var w = Math.round( src.width * scale );
			var h = Math.round( src.height * scale );
			ctx.drawImage( src, Math.round( ( target.width - w ) / 2 ), Math.round( ( target.height - h ) / 2 ), w, h );
			target.toBlob( function ( blob ) {
				var base = ( queue.currentName || 'photo' ).replace( /\.[^.]+$/, '' );
				var file = new File( [ blob ], base + '.jpg', { type: 'image/jpeg' } );
				if ( editCb ) {
					var cb = editCb;
					editCb = null;
					teardown();
					cb( file );
					return;
				}
				doneFiles.push( file );
				nextInQueue();
			}, 'image/jpeg', 0.9 );
		} );
		overlay.querySelector( '.cp-crop-skip' ).addEventListener( 'click', function () {
			if ( editCb ) {
				editCb = null;
				teardown();
				return;
			}
			doneFiles.push( queue.currentFile );
			nextInQueue();
		} );
		overlay.querySelector( '.cp-crop-cancel' ).addEventListener( 'click', function () {
			editCb = null;
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

	/** Open the cropper on an existing image URL; cb receives the new File. */
	window.cpCropExisting = function ( src, ratio, cb ) {
		if ( ! overlay ) {
			buildOverlay();
		}
		activeInput = null;
		ratioMode = ratio || 'free';
		editCb = cb;
		queue = { files: [], currentName: 'image.jpg', currentFile: null };
		doneFiles = [];
		overlay.style.display = 'flex';
		if ( cropper ) {
			cropper.destroy();
			cropper = null;
		}
		imgEl.crossOrigin = 'anonymous';
		imgEl.src = src + ( src.indexOf( '?' ) === -1 ? '?' : '&' ) + 'cpcrop=' + Date.now();
		imgEl.onload = function () {
			imgEl.onload = null;
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
	};

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( 'input[type="file"][data-cp-crop]' ).forEach( function ( input ) {
			input.addEventListener( 'change', onPick );
		} );

		// Edit buttons on existing event images.
		document.querySelectorAll( '.cp-recrop' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				window.cpCropExisting( btn.getAttribute( 'data-src' ), 'free', function ( file ) {
					var form = btn.closest( 'form' );
					if ( ! form ) {
						return;
					}
					var name = 'recrop_' + btn.getAttribute( 'data-att' );
					var input = form.querySelector( 'input[name="' + name + '"]' );
					if ( ! input ) {
						input = document.createElement( 'input' );
						input.type = 'file';
						input.name = name;
						input.style.display = 'none';
						form.appendChild( input );
					}
					try {
						var dt = new DataTransfer();
						dt.items.add( file );
						input.files = dt.files;
						btn.textContent = 'Edited \u2713 (applies on save)';
					} catch ( err ) {
						btn.textContent = 'Browser not supported';
					}
				} );
			} );
		} );
	} );
} )();
