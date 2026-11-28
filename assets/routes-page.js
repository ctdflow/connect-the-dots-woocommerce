/**
 * Routes tab of Connect the Dots: sends the plan, polls until it is done and copies driver links.
 *
 * The server renders and escapes every piece of HTML that is inserted here.
 */
( function () {
	'use strict';

	const config = window.ctdflowRoutes;
	const form = document.getElementById( 'ctdflow-plan-form' );
	const result = document.getElementById( 'ctdflow-result' );
	const messages = document.getElementById( 'ctdflow-messages' );

	if ( ! config ) {
		return;
	}

	/**
	 * Posts to admin-ajax and resolves with the JSON answer of the plugin: an object with a boolean
	 * success and a data object that passes isValid. Anything else rejects. WordPress answers -1 for a
	 * nonce that expired and 0 when the login expired; those reject with expired set.
	 */
	function send( fields, isValid ) {
		fields.set( 'nonce', config.nonce );

		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: fields,
		} ).then( ( response ) =>
			response.text().then( ( text ) => {
				let body = null;

				try {
					body = JSON.parse( text );
				} catch ( error ) {
					body = null;
				}

				if (
					body &&
					'object' === typeof body &&
					'boolean' === typeof body.success &&
					body.data &&
					'object' === typeof body.data &&
					( ! body.success || isValid( body.data ) )
				) {
					return body;
				}

				const failure = new Error( 'Unexpected answer from admin-ajax.' );
				failure.expired = [ '-1', '0' ].includes( text.trim() ) || 401 === response.status || 403 === response.status;

				throw failure;
			} )
		);
	}

	function failureText( error ) {
		return error && error.expired ? config.strings.expired : config.strings.failed;
	}

	function setBusy( busy ) {
		form.querySelectorAll( 'button[type="submit"]' ).forEach( ( button ) => {
			button.disabled = busy;
		} );
	}

	function showText( element, text ) {
		const paragraph = document.createElement( 'p' );
		paragraph.textContent = text;
		element.replaceChildren( paragraph );
	}

	/**
	 * Polls the plan of a delivery date. The date is taken when planning starts, so changing the date
	 * field meanwhile does not show the routes of another day.
	 */
	function poll( date ) {
		const started = Date.now();

		function tick() {
			const fields = new FormData();
			fields.set( 'action', 'ctdflow_plan_status' );
			fields.set( 'date', date );

			send( fields, ( data ) => 'boolean' === typeof data.done && 'string' === typeof data.html )
				.then( ( response ) => {
					const data = response.data;

					if ( response.success && ! data.done ) {
						if ( Date.now() - started >= config.timeout ) {
							showText( result, config.strings.slow );
							setBusy( false );
							return;
						}

						window.setTimeout( tick, config.interval );
						return;
					}

					result.innerHTML = data.html || '';
					setBusy( false );
				} )
				.catch( ( error ) => {
					showText( result, failureText( error ) );
					setBusy( false );
				} );
		}

		setBusy( true );
		window.setTimeout( tick, config.interval );
	}

	/**
	 * Shows the plan and free plan line the server sent after planning, which used stops of this month.
	 *
	 * @param {Object} data Data of the plan response.
	 */
	function showAccount( data ) {
		const label = document.querySelector( '.ctdflow-plan-label' );
		const tier = document.querySelector( '.ctdflow-tier' );

		if ( label && 'string' === typeof data.plan_label ) {
			label.textContent = data.plan_label;
		}

		if ( tier && 'string' === typeof data.tier_html ) {
			tier.innerHTML = data.tier_html;
		}
	}

	if ( form && result ) {
		form.addEventListener( 'submit', ( event ) => {
			event.preventDefault();

			const fields = new FormData( form );
			const date = fields.get( 'date' );

			fields.set( 'action', 'ctdflow_plan' );
			messages.replaceChildren();
			setBusy( true );

			send( fields, ( data ) => 'string' === typeof data.html )
				.then( ( response ) => {
					const data = response.data;

					if ( response.success ) {
						result.innerHTML = data.html || '';
						showAccount( data );
						poll( date );
						return;
					}

					messages.innerHTML = data.html || '';

					setBusy( false );
				} )
				.catch( ( error ) => {
					showText( messages, failureText( error ) );
					setBusy( false );
				} );
		} );

		if ( '1' === result.dataset.ctdflowPending ) {
			poll( form.elements.date.value );
		}
	}

	document.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '[data-ctdflow-copy]' );

		if ( ! button ) {
			return;
		}

		const input = document.getElementById( button.dataset.ctdflowCopy );

		if ( ! input ) {
			return;
		}

		const label = button.textContent;
		const show = ( text, duration ) => {
			button.textContent = text;
			window.setTimeout( () => {
				button.textContent = label;
			}, duration );
		};

		// Without clipboard access the link is selected, so the planner can copy it by hand.
		const copyBySelecting = () => {
			input.select();

			let copied = false;

			try {
				copied = document.execCommand( 'copy' );
			} catch ( error ) {
				copied = false;
			}

			if ( copied ) {
				show( config.strings.copied, 2000 );
			} else {
				show( config.strings.copyManually, 5000 );
			}
		};

		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard
				.writeText( input.value )
				.then( () => show( config.strings.copied, 2000 ) )
				.catch( copyBySelecting );
			return;
		}

		copyBySelecting();
	} );
}() );
