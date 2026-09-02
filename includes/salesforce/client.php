<?php
/**
 * Authenticated Salesforce REST requests.
 *
 * Owns pagination (following every `nextRecordsUrl` until `done`), response
 * validation, and the one-time invalid-session retry. Callers only ever see
 * a flat array of records or a structured WP_Error — never a raw HTTP
 * response.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\Salesforce\Client;

use SalesforceGravityForms\Config;
use SalesforceGravityForms\Salesforce\Authentication;
use SalesforceGravityForms\Helpers\Utilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Finite request timeout so an unreachable/slow Salesforce instance cannot
// hang a WordPress request indefinitely.
const REQUEST_TIMEOUT_SECONDS = 20;

// Hard ceiling on the number of `nextRecordsUrl` pages we will follow for a
// single query. Salesforce's real page size (2,000 records/page) means this
// comfortably covers any plausible sponsor list while still guaranteeing we
// never loop indefinitely on a malformed/looping response.
const MAX_PAGES = 200;

/**
 * Executes a SOQL query and returns every matching record, following
 * pagination and retrying exactly once if the cached token has gone stale.
 *
 * @param string $soql SOQL statement to execute.
 * @return array<int, array<string, mixed>>|\WP_Error
 */
function query( $soql ) {
	$auth = Authentication\authenticate();
	if ( is_wp_error( $auth ) ) {
		return $auth;
	}

	$result = execute_query( $auth, $soql );

	// If — and only if — the token went stale between caching and use,
	// drop it and retry the whole query exactly once with a fresh token.
	if ( is_wp_error( $result ) && 'sfgf_invalid_session' === $result->get_error_code() ) {
		Authentication\invalidate();

		$auth = Authentication\authenticate();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		$result = execute_query( $auth, $soql );

		// A second invalid-session response means something is wrong beyond
		// a simple expired token; surface a controlled error instead of
		// retrying again.
		if ( is_wp_error( $result ) && 'sfgf_invalid_session' === $result->get_error_code() ) {
			return new \WP_Error(
				'sfgf_query_failed',
				__( 'Salesforce rejected the request even after re-authenticating.', 'salesforce-gravity-forms' )
			);
		}
	}

	return $result;
}

/**
 * Runs one query attempt (with the given, already-authenticated credentials)
 * to completion, following pagination.
 *
 * @param array{access_token: string, instance_url: string} $auth Authenticated token/instance bundle.
 * @param string                                             $soql  SOQL statement to execute.
 * @return array<int, array<string, mixed>>|\WP_Error
 */
function execute_query( $auth, $soql ) {
	$api_version = Config\get_api_version();
	$path        = '/services/data/v' . $api_version . '/query?q=' . rawurlencode( $soql );

	$records = [];
	$next    = $path;
	$pages   = 0;

	// Follow every `nextRecordsUrl` until Salesforce reports `done`, capped
	// by MAX_PAGES so a malformed response can never cause an infinite loop.
	while ( null !== $next && $pages < MAX_PAGES ) {
		$page = fetch_page( $auth, $next );
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		$records = array_merge( $records, $page['records'] );
		$next    = $page['done'] ? null : $page['next_records_url'];
		$pages++;
	}

	return $records;
}

/**
 * Fetches one page of query results from an absolute-or-relative Salesforce
 * REST path.
 *
 * @param array{access_token: string, instance_url: string} $auth Authenticated token/instance bundle.
 * @param string                                             $path Path (or `nextRecordsUrl`) relative to the instance URL.
 * @return array{records: array, done: bool, next_records_url: string|null}|\WP_Error
 */
function fetch_page( $auth, $path ) {
	$url = $auth['instance_url'] . $path;

	$response = wp_remote_get(
		$url,
		[
			'timeout' => REQUEST_TIMEOUT_SECONDS,
			'headers' => [
				'Authorization' => 'Bearer ' . $auth['access_token'],
			],
		]
	);

	if ( is_wp_error( $response ) ) {
		// A network-level WP_Error never contains the Authorization header.
		Utilities\log( 'error', 'Salesforce query request failed to connect', [ 'error' => $response->get_error_code() ] );
		return $response;
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	$body        = json_decode( wp_remote_retrieve_body( $response ), true );

	// Salesforce reports an expired/invalid session as a 401 with an
	// errorCode of INVALID_SESSION_ID in the (array of one) JSON body.
	if ( 401 === $status_code && is_array( $body ) && isset( $body[0]['errorCode'] ) && 'INVALID_SESSION_ID' === $body[0]['errorCode'] ) {
		return new \WP_Error( 'sfgf_invalid_session', __( 'Salesforce session is no longer valid.', 'salesforce-gravity-forms' ) );
	}

	if ( 200 !== $status_code || ! is_array( $body ) || ! isset( $body['records'] ) ) {
		// Log the status and, when Salesforce provided one, its error code —
		// never the full body, which could include query results.
		Utilities\log(
			'error',
			'Salesforce query request was rejected',
			[
				'status'     => $status_code,
				'error_code' => is_array( $body ) ? ( $body[0]['errorCode'] ?? null ) : null,
			]
		);
		return new \WP_Error(
			'sfgf_query_failed',
			__( 'Salesforce query failed.', 'salesforce-gravity-forms' )
		);
	}

	return [
		'records'          => $body['records'],
		'done'             => ! empty( $body['done'] ),
		'next_records_url' => $body['nextRecordsUrl'] ?? null,
	];
}
