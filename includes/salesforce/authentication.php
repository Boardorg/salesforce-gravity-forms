<?php
/**
 * Salesforce OAuth 2.0 Client Credentials authentication.
 *
 * Mirrors the existing app's lib/salesforce/client.ts authenticate() flow:
 * a Client Credentials POST to /services/oauth2/token, a cached token, and
 * no JWT/RSA involvement. This module knows how to obtain a token; it knows
 * nothing about SOQL or Gravity Forms.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\Salesforce\Authentication;

use SalesforceGravityForms\Config;
use SalesforceGravityForms\Cache\TokenCache;
use SalesforceGravityForms\Helpers\Utilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Lock key guarding concurrent token fetches so simultaneous page requests
// don't all miss the cache and hit the token endpoint at once.
const AUTH_LOCK_KEY = 'sfgf_auth_lock';

// Finite timeouts so a slow/unreachable Salesforce endpoint cannot hang a
// WordPress request indefinitely.
const REQUEST_TIMEOUT_SECONDS = 15;

/**
 * Returns a valid Salesforce access token and instance URL, fetching and
 * caching a fresh one if needed.
 *
 * @return array{access_token: string, instance_url: string}|\WP_Error
 */
function authenticate() {
	// Fast path: reuse the cached token if it's still within its TTL.
	$cached = TokenCache\get_cached_token();
	if ( null !== $cached ) {
		return $cached;
	}

	// Try to become the single request that actually fetches a new token.
	if ( ! Utilities\acquire_lock( AUTH_LOCK_KEY ) ) {
		// Another request is already fetching; wait briefly for its result
		// to land in the cache instead of issuing a redundant token request.
		$result = Utilities\wait_for(
			function () {
				return TokenCache\get_cached_token();
			}
		);
		if ( null !== $result ) {
			return $result;
		}
		// Timed out waiting — fall through and fetch it ourselves rather
		// than fail the request outright.
	}

	$token = fetch_token();

	// Always release the lock, whether the fetch succeeded or failed.
	Utilities\release_lock( AUTH_LOCK_KEY );

	return $token;
}

/**
 * Clears the cached token, forcing the next authenticate() call to fetch a
 * fresh one. Called by the client after a Salesforce INVALID_SESSION_ID
 * response.
 *
 * @return void
 */
function invalidate() {
	TokenCache\clear_cached_token();
}

/**
 * Performs the OAuth 2.0 Client Credentials exchange against Salesforce's
 * token endpoint.
 *
 * @return array{access_token: string, instance_url: string}|\WP_Error
 */
function fetch_token() {
	$credentials = Config\get_credentials();
	if ( is_wp_error( $credentials ) ) {
		return $credentials;
	}

	$token_url = trailingslashit( $credentials['login_url'] ) . 'services/oauth2/token';

	// wp_remote_post() form-encodes an array body, matching the
	// application/x-www-form-urlencoded content type Salesforce's token
	// endpoint expects.
	$response = wp_remote_post(
		$token_url,
		[
			'timeout' => REQUEST_TIMEOUT_SECONDS,
			'body'    => [
				'grant_type'    => 'client_credentials',
				'client_id'     => $credentials['client_id'],
				'client_secret' => $credentials['client_secret'],
			],
		]
	);

	if ( is_wp_error( $response ) ) {
		// wp_remote_post()'s own WP_Error (network failure, timeout, etc.)
		// never contains the request body, so it's already safe to log.
		Utilities\log( 'error', 'Salesforce token request failed to connect', [ 'error' => $response->get_error_code() ] );
		return $response;
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	$body        = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 200 !== $status_code || empty( $body['access_token'] ) || empty( $body['instance_url'] ) ) {
		// Log only the status and Salesforce's error code, never the
		// response body (which could echo back request parameters).
		Utilities\log(
			'error',
			'Salesforce token request was rejected',
			[
				'status'     => $status_code,
				'error_code' => is_array( $body ) ? ( $body['error'] ?? null ) : null,
			]
		);
		return new \WP_Error(
			'sfgf_auth_failed',
			__( 'Unable to authenticate with Salesforce.', 'salesforce-gravity-forms' )
		);
	}

	$token = [
		'access_token' => $body['access_token'],
		'instance_url' => $body['instance_url'],
	];

	TokenCache\set_cached_token( $token['access_token'], $token['instance_url'] );

	return $token;
}
