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

	window.ConfigKit = window.ConfigKit || {};
	window.ConfigKit.request = request;
} )();
