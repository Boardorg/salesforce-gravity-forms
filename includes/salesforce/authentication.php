<?php
/**
 * Gets and caches a Salesforce access token using OAuth 2.0 Client Credentials.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\Salesforce\Authentication;

// Set our aliases.
use SalesforceGravityForms\Config;
use SalesforceGravityForms\Cache\TokenCache;
use SalesforceGravityForms\Helpers\Utilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Define a lock key so multiple requests don't all fetch a token at once.
const AUTH_LOCK_KEY = 'sfgf_auth_lock';

// Define a timeout so a slow Salesforce endpoint can't hang the request.
const REQUEST_TIMEOUT_SECONDS = 15;

/**
 * Returns a Salesforce access token and instance URL, fetching and caching
 * a fresh one if needed.
 *
 * @return array{access_token: string, instance_url: string}|\WP_Error
 */
function authenticate() {

	// Reuse the cached token if it hasn't expired.
	$cached = TokenCache\get_cached_token();
	if ( null !== $cached ) {
		return $cached;
	}

	// Check if we are already fetching a token in another request.
	if ( ! Utilities\acquire_lock( AUTH_LOCK_KEY ) ) {

		// Another request is already fetching so wait for its result instead.
		$result = Utilities\wait_for(
			function () {
				return TokenCache\get_cached_token();
			}
		);

		// If we got a result, return it.
		if ( null !== $result ) {
			return $result;
		}
	}

	// Give up waiting and fetch it ourselves.
	$token = fetch_token();

	// Always release the lock, whether the fetch succeeded or failed.
	Utilities\release_lock( AUTH_LOCK_KEY );

	// Return the token (or error).
	return $token;
}

/**
 * Clears the cached token, forcing the next authenticate() call to fetch a fresh one.
 *
 * @return void
 */
function invalidate() {
	TokenCache\clear_cached_token();
}

/**
 * Requests a new access token from Salesforce using the Client Credentials flow.
 *
 * @return array{access_token: string, instance_url: string}|\WP_Error
 */
function fetch_token() {

	// Read the configured credentials and bail if we're missing any.
	$credentials = Config\get_credentials();
	if ( is_wp_error( $credentials ) ) {
		return $credentials;
	}

	// Build the token endpoint URL.
	$token_url = trailingslashit( $credentials['login_url'] ) . 'services/oauth2/token';

	// Send the request.
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

	// Did the request fail to connect or time out?
	if ( is_wp_error( $response ) ) {

		// A connection failure is already safe to log, so log it and bail.
		Utilities\log( 'error', 'Salesforce token request failed to connect', [ 'error' => $response->get_error_code() ] );
		return $response;
	}

	// Read the response.
	$status_code = wp_remote_retrieve_response_code( $response );
	$body        = json_decode( wp_remote_retrieve_body( $response ), true );

	// Did Salesforce reject the request or return a malformed response?
	if ( 200 !== $status_code || empty( $body['access_token'] ) || empty( $body['instance_url'] ) ) {

		// Get the error code and description if Salesforce provided them.
		$error_code = is_array( $body ) ? ( $body['error'] ?? null ) : null;
		$error_description = is_array( $body ) ? ( $body['error_description'] ?? null ) : null;

		// Log the error and return a WP_Error.
		Utilities\log(
			'error',
			'Salesforce token request was rejected',
			[
				'status'            => $status_code,
				'error_code'        => $error_code,
				'error_description' => $error_description,
			]
		);
		return new \WP_Error(
			'sfgf_auth_failed',
			$error_code
				? sprintf(
					/* translators: 1: Salesforce's OAuth error code (e.g. invalid_client), 2: its description. */
					__( 'Salesforce rejected the request: %1$s (%2$s)', 'salesforce-gravity-forms' ),
					$error_code,
					$error_description ? $error_description : __( 'no further detail provided', 'salesforce-gravity-forms' )
				)
				: __( 'Unable to authenticate with Salesforce.', 'salesforce-gravity-forms' )
		);
	}

	// Set up the token array to return and cache.
	$token = [
		'access_token' => $body['access_token'],
		'instance_url' => $body['instance_url'],
	];

	// Cache the token for future requests.
	TokenCache\set_cached_token( $token['access_token'], $token['instance_url'] );

	// Return the token.
	return $token;
}
