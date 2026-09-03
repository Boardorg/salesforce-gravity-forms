<?php
/**
 * Runs authenticated Salesforce queries. Follows pagination, retries once
 * if the session expired, and always returns either an array of records or
 * a WP_Error.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\Salesforce\Client;

// Set our aliases.
use SalesforceGravityForms\Config;
use SalesforceGravityForms\Salesforce\Authentication;
use SalesforceGravityForms\Helpers\Utilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Define a timeout so a slow Salesforce endpoint can't hang the request.
const REQUEST_TIMEOUT_SECONDS = 20;

// Define a limit on how many pages we'll follow, so a bad response can't loop forever.
const MAX_PAGES = 200;

/**
 * Executes a SOQL query and returns every matching record, following
 * pagination and retrying exactly once if the cached token has gone stale.
 *
 * @param string $soql SOQL statement to execute.
 * @return array<int, array<string, mixed>>|\WP_Error
 */
function query( $soql ) {

	// Authenticate and bail if it fails.
	$auth = Authentication\authenticate();
	if ( is_wp_error( $auth ) ) {
		return $auth;
	}

	// Run the query.
	$result = execute_query( $auth, $soql );

	// Did we get an invalid-session error? Clear the token and retry once.
	if ( is_wp_error( $result ) && 'sfgf_invalid_session' === $result->get_error_code() ) {

		// Clear the stale token and authenticate again.
		Authentication\invalidate();
		$auth = Authentication\authenticate();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		// Retry the query with the fresh token.
		$result = execute_query( $auth, $soql );

		// Still invalid after retrying? Give up rather than retry again.
		if ( is_wp_error( $result ) && 'sfgf_invalid_session' === $result->get_error_code() ) {
			return new \WP_Error(
				'sfgf_query_failed',
				__( 'Salesforce rejected the request even after re-authenticating.', 'salesforce-gravity-forms' )
			);
		}
	}

	// Return the result (or error).
	return $result;
}

/**
 * Runs one query attempt to completion, following pagination.
 *
 * @param array{access_token: string, instance_url: string} $auth Authenticated token/instance bundle.
 * @param string                                             $soql  SOQL statement to execute.
 * @return array<int, array<string, mixed>>|\WP_Error
 */
function execute_query( $auth, $soql ) {

	// Build the query URL.
	$api_version = Config\get_api_version();
	$path        = '/services/data/v' . $api_version . '/query?q=' . rawurlencode( $soql );

	// Set up an array to collect every record across all pages, and a page counter.
	$records = [];
	$next    = $path;
	$pages   = 0;

	// Follow every page until Salesforce reports done, or we hit the page limit.
	while ( null !== $next && $pages < MAX_PAGES ) {

		// Fetch this page and bail if it fails.
		$page = fetch_page( $auth, $next );
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		// Add its records and move to the next page, if any.
		$records = array_merge( $records, $page['records'] );
		$next    = $page['done'] ? null : $page['next_records_url'];
		$pages++;
	}

	// Return every record collected across all pages.
	return $records;
}

/**
 * Fetches one page of query results from a Salesforce REST path.
 *
 * @param array{access_token: string, instance_url: string} $auth Authenticated token/instance bundle.
 * @param string                                             $path Path (or `nextRecordsUrl`) relative to the instance URL.
 * @return array{records: array, done: bool, next_records_url: string|null}|\WP_Error
 */
function fetch_page( $auth, $path ) {

	// Build the full URL and send the request.
	$url      = $auth['instance_url'] . $path;
	$response = wp_remote_get(
		$url,
		[
			'timeout' => REQUEST_TIMEOUT_SECONDS,
			'headers' => [
				'Authorization' => 'Bearer ' . $auth['access_token'],
			],
		]
	);

	// Did the request fail to connect or time out?
	if ( is_wp_error( $response ) ) {

		// Log the error.
		Utilities\log( 'error', 'Salesforce query request failed to connect', [ 'error' => $response->get_error_code() ] );
		return $response;
	}

	// Read the response.
	$status_code = wp_remote_retrieve_response_code( $response );
	$body        = json_decode( wp_remote_retrieve_body( $response ), true );

	// Did Salesforce report an expired session (a 401 with INVALID_SESSION_ID)?
	if ( 401 === $status_code && is_array( $body ) && isset( $body[0]['errorCode'] ) && 'INVALID_SESSION_ID' === $body[0]['errorCode'] ) {
		return new \WP_Error( 'sfgf_invalid_session', __( 'Salesforce session is no longer valid.', 'salesforce-gravity-forms' ) );
	}

	// Did Salesforce reject the request or return something we don't recognize?
	if ( 200 !== $status_code || ! is_array( $body ) || ! isset( $body['records'] ) ) {

		// Log the status/error code only.
		Utilities\log(
			'error',
			'Salesforce query request was rejected',
			[
				'status'     => $status_code,
				'error_code' => is_array( $body ) ? ( $body[0]['errorCode'] ?? null ) : null,
			]
		);

		// Return a generic error to avoid exposing Salesforce details to the user.
		return new \WP_Error(
			'sfgf_query_failed',
			__( 'Salesforce query failed.', 'salesforce-gravity-forms' )
		);
	}

	// Return this page's records plus pagination state.
	return [
		'records'          => $body['records'],
		'done'             => ! empty( $body['done'] ),
		'next_records_url' => $body['nextRecordsUrl'] ?? null,
	];
}
