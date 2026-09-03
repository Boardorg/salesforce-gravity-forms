<?php
/**
 * Persists the Salesforce access token across requests.
 *
 * A WordPress request is a fresh PHP process, so this cache has to be
 * an explicit, persistent store using transients.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\Cache\TokenCache;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Transient key the cached token bundle is stored under.
const TRANSIENT_KEY = 'sfgf_sf_token';

// How long a fetched token is trusted before authenticate() fetches a fresh one.
const TOKEN_TTL_SECONDS = 25 * MINUTE_IN_SECONDS;

/**
 * Returns the cached token bundle, or null if there is none or it has expired.
 *
 * @return array{access_token: string, instance_url: string, expires_at: int}|null
 */
function get_cached_token() {
	$cached = get_transient( TRANSIENT_KEY );

	// Bail if get_transient() returns false for a missing/expired transient.
	if ( false === $cached || ! is_array( $cached ) ) {
		return null;
	}

	// Also check our own expires_at in case it drifts from the transient's expiration.
	if ( ! isset( $cached['expires_at'] ) || $cached['expires_at'] <= time() ) {
		return null;
	}

	return $cached;
}

/**
 * Stores a freshly fetched token bundle.
 *
 * @param string $access_token Salesforce OAuth access token.
 * @param string $instance_url Salesforce instance URL to send subsequent REST calls to.
 * @return void
 */
function set_cached_token( $access_token, $instance_url ) {

	// Build a bundle with the token, instance URL, and our own expiration timestamp.
	$bundle = [
		'access_token' => $access_token,
		'instance_url' => $instance_url,
		'expires_at'   => time() + TOKEN_TTL_SECONDS,
	];

	// Store the bundle in a transient.
	set_transient( TRANSIENT_KEY, $bundle, TOKEN_TTL_SECONDS );
}

/**
 * Clears the cached token, forcing the next authenticate() call to fetch a
 * fresh one.
 *
 * @return void
 */
function clear_cached_token() {
	delete_transient( TRANSIENT_KEY );
}
