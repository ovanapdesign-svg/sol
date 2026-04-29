/* global CONFIGKIT */
( function () {
	'use strict';

	if ( typeof window.CONFIGKIT === 'undefined' ) {
		return;
	}

	/**
	 * Wrapper around fetch that:
	 *  - prefixes paths with the REST namespace
	 *  - sends the wp_rest nonce
	 *  - parses JSON and surfaces WP_Error structures consistently
	 */
	async function request( path, options = {} ) {
		const init = Object.assign(
			{
				method: 'GET',
				credentials: 'same-origin',
				headers: {},
			},
			options
		);

		init.headers = Object.assign(
			{
				'Content-Type': 'application/json',
				Accept: 'application/json',
				'X-WP-Nonce': window.CONFIGKIT.nonce,
			},
			init.headers || {}
		);

		if ( init.body && typeof init.body !== 'string' ) {
			init.body = JSON.stringify( init.body );
		}

		const url = window.CONFIGKIT.restUrl + path;
		const response = await fetch( url, init );
		let payload = null;
		try {
			payload = await response.json();
		} catch ( e ) {
			payload = null;
		}

		if ( ! response.ok ) {
			const error = new Error(
				payload && payload.message ? payload.message : response.statusText
			);
			error.code = payload && payload.code ? payload.code : 'http_' + response.status;
			error.status = response.status;
			error.data = payload && payload.data ? payload.data : null;
			throw error;
		}

		return payload;
	}

	/**
	 * Translate a thrown REST error into a user-friendly description.
	 *
	 * Returned shape:
	 *   {
	 *     kind: 'error' | 'conflict',
	 *     friendly: string,        // shown to the owner
	 *     technical: string,       // raw message + code, hidden by default
	 *     showFieldErrors: bool,   // if true, also surface err.data.errors[*]
	 *   }
	 *
	 * 400 / 422 are treated as validation: friendly text is the server
	 * message (already meaningful), and field-level errors are surfaced
	 * separately by the page-specific JS via err.data.errors.
	 */
	function describeError( err ) {
		const status = err && err.status ? err.status : 0;
		const code = err && err.code ? err.code : '';
		const rawMessage = err && err.message ? err.message : '';
		const technical = rawMessage + ( code ? ' (' + code + ')' : '' )
			+ ( status ? ' [HTTP ' + status + ']' : '' );

		const isNoRoute = code === 'rest_no_route' || code === 'no_route';

		if ( status === 404 || isNoRoute ) {
			return {
				kind: 'error',
				friendly: 'Could not load this section. Try refreshing the page.',
				technical: technical,
				showFieldErrors: false,
			};
		}
		if ( status === 401 || status === 403 ) {
			return {
				kind: 'error',
				friendly: "You don't have permission for this action.",
				technical: technical,
				showFieldErrors: false,
			};
		}
		if ( status === 409 ) {
			return {
				kind: 'conflict',
				friendly:
					'Someone else changed this record while you were editing. Please reload and try again.',
				technical: technical,
				showFieldErrors: false,
			};
		}
		if ( status === 400 || status === 422 ) {
			return {
				kind: 'error',
				friendly: rawMessage || 'Please check the errors highlighted below.',
				technical: technical,
				showFieldErrors: true,
			};
		}
		return {
			kind: 'error',
			friendly: 'An unexpected error occurred. Please try again or contact support.',
			technical: technical,
			showFieldErrors: false,
		};
	}

	window.ConfigKit = window.ConfigKit || {};
	window.ConfigKit.request = request;
	window.ConfigKit.describeError = describeError;
} )();
