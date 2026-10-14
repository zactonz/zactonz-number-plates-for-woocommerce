/**
 * Number plate configurator.
 *
 * Deliberately thin. The plate is drawn on the server by the same renderer that
 * produces the merchant's production artwork, so there is no layout logic here to
 * drift away from it - this file collects values, asks, and shows the answer.
 */
( function () {
	'use strict';

	var config = window.zctzNumberPlates;

	if ( ! config || ! config.endpoint ) {
		return;
	}

	var root = document.querySelector( '[data-zctz-np]' );

	if ( ! root ) {
		return;
	}

	// All three layouts carry the same controls; only their arrangement differs.
	if ( ! root.querySelector( '[data-zctz-np-variant]' ) ) {
		return;
	}

	var preview = root.querySelector( '[data-zctz-np-preview]' );
	var errors = root.querySelector( '[data-zctz-np-errors]' );
	var price = root.querySelector( '[data-zctz-np-price]' );
	var status = root.querySelector( '[data-zctz-np-status]' );
	var variants = root.querySelectorAll( '[data-zctz-np-variant]' );
	var sizes = root.querySelectorAll( '[data-zctz-np-size]' );
	var inputs = root.querySelectorAll( '[data-zctz-np-input]' );
	var timer = null;
	var inFlight = null;

	// The server drew an empty plate into the box. Keep it, so clearing the fields puts
	// the plate back rather than leaving a hole where it was.
	var blank = preview.innerHTML;

	/**
	 * Returns the chosen radio in a group, or null when none is chosen yet.
	 */
	function chosen( group ) {
		for ( var i = 0; i < group.length; i++ ) {
			if ( group[ i ].checked ) {
				return group[ i ];
			}
		}

		return null;
	}

	/**
	 * Narrows the sizes to the ones the chosen plate type allows.
	 *
	 * Every size is in the markup; the ones this type cannot use are hidden rather than
	 * removed, so switching type back does not need the page rebuilt. A hidden option is
	 * also disabled, because a hidden-but-enabled radio is still reachable by keyboard.
	 */
	function syncSizes() {
		var current = chosen( variants );
		var allowed = ( current && current.dataset.sizes ? current.dataset.sizes : '' ).split( ',' );
		var firstAllowed = null;
		var stillAllowed = false;

		Array.prototype.forEach.call( sizes, function ( radio ) {
			var ok = allowed.indexOf( radio.dataset.key ) !== -1;
			var wrap = radio.closest( '[data-zctz-np-size-option]' );

			radio.disabled = ! ok;

			if ( wrap ) {
				wrap.hidden = ! ok;
			}

			if ( ! ok ) {
				return;
			}

			if ( firstAllowed === null ) {
				firstAllowed = radio;
			}

			if ( radio.checked ) {
				stillAllowed = true;
			}
		} );

		if ( ! stillAllowed && firstAllowed ) {
			firstAllowed.checked = true;
		}
	}

	function values() {
		var out = { product: config.product };
		var variant = chosen( variants );
		var size = chosen( sizes );

		out.variant = variant ? variant.value : '';
		out.size = size ? size.value : '';

		Array.prototype.forEach.call( inputs, function ( input ) {
			out[ input.dataset.zctzNpInput ] = input.value;
		} );

		return out;
	}

	function show( data ) {
		if ( ! data || typeof data !== 'object' ) {
			return;
		}

		preview.innerHTML = data.svg || blank;
		errors.textContent = ( data.errors && data.errors.length ) ? data.errors[ 0 ] : '';

		// Markup because it is WooCommerce's own wc_price() output, rendered server-side.
		if ( price ) {
			price.innerHTML = data.price || '';
		}

		root.classList.toggle( 'is-invalid', ! data.valid );

		/*
		 * The plate is a picture, and the picture's own element is not announced: this
		 * line is. It says what was drawn, in words, once per update.
		 */
		if ( status ) {
			status.textContent = data.valid ? describe() : '';
		}
	}

	/**
	 * Returns a sentence describing what is on the plate right now.
	 */
	function describe() {
		var size = chosen( sizes );
		var parts = [];

		Array.prototype.forEach.call( inputs, function ( input ) {
			if ( input.value ) {
				parts.push( input.value );
			}
		} );

		if ( size ) {
			var label = root.querySelector( 'label[for="' + size.id + '"]' );

			if ( label ) {
				parts.push( label.textContent.trim() );
			}
		}

		return parts.join( ', ' );
	}

	function request() {
		var payload = values();

		if ( ! payload.district && ! payload.letters && ! payload.digits ) {
			preview.innerHTML = blank;
			errors.textContent = '';

			if ( price ) {
				price.innerHTML = '';
			}

			if ( status ) {
				status.textContent = '';
			}

			root.classList.remove( 'is-invalid' );
			return;
		}

		if ( inFlight ) {
			inFlight.abort();
		}

		inFlight = new AbortController();

		/*
		 * Said on the status line rather than beside the plate: the preview is a picture
		 * that simply changes, so someone who cannot see it changing is the one who
		 * needs telling that an answer is on its way.
		 */
		if ( status && config.strings && config.strings.checking ) {
			status.textContent = config.strings.checking;
		}

		window.fetch( config.endpoint, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce
			},
			body: JSON.stringify( payload ),
			signal: inFlight.signal
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'zctz-np: ' + response.status );
				}

				return response.json();
			} )
			.then( show )
			.catch( function ( error ) {
				// An aborted request is the expected outcome of typing quickly.
				if ( error && error.name === 'AbortError' ) {
					return;
				}

				/*
				 * Something between here and the shop failed - offline, a proxy, a
				 * security plugin refusing the route. Saying nothing would leave the
				 * last plate on screen looking like the answer to what was just typed,
				 * so the preview is cleared and the reason given. Nothing is lost by it:
				 * the plate is validated again on the server when it is added.
				 */
				preview.innerHTML = blank;
				errors.textContent = ( config.strings && config.strings.failed ) ? config.strings.failed : '';
				root.classList.remove( 'is-invalid' );

				if ( price ) {
					price.innerHTML = '';
				}

				if ( status ) {
					status.textContent = '';
				}
			} );
	}

	function schedule() {
		window.clearTimeout( timer );
		timer = window.setTimeout( request, 250 );
	}

	Array.prototype.forEach.call( inputs, function ( input ) {
		input.addEventListener( 'input', function () {
			var start = input.selectionStart;
			input.value = input.value.toUpperCase();
			input.setSelectionRange( start, start );
			schedule();
		} );
	} );

	Array.prototype.forEach.call( variants, function ( radio ) {
		radio.addEventListener( 'change', function () {
			syncSizes();
			schedule();
		} );
	} );

	Array.prototype.forEach.call( sizes, function ( radio ) {
		radio.addEventListener( 'change', schedule );
	} );

	syncSizes();
}() );
