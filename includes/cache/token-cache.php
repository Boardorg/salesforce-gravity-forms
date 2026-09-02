<?php
/**
 * Persists the Salesforce access token across requests.
 *
 * A WordPress request is a fresh PHP process, so — unlike the existing
 * Node app's module-level `cached` variable, which lives for the process's
 * whole lifetime — this cache has to be an explicit, persistent store.
 * Transients (backed by VIP's persistent object cache) fill that role.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\Cache\TokenCache;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Transient key the cached token bundle is stored under.
const TRANSIENT_KEY = 'sfgf_sf_token';

// How long a fetched token is trusted before authenticate() fetches a fresh
// one. Intentionally shorter than the existing app's ~30 minute cache to
// leave a safety margin under the Connected App session timeout.
const TOKEN_TTL_SECONDS = 25 * MINUTE_IN_SECONDS;

/**
 * Returns the cached token bundle, or null if there is none or it has
 * expired.
 *
 * @return array{access_token: string, instance_url: string, expires_at: int}|null
 */
function get_cached_token() {
	$cached = get_transient( TRANSIENT_KEY );

	// get_transient() returns false for a missing/expired transient.
	if ( false === $cached || ! is_array( $cached ) ) {
		return null;
	}

	// Belt-and-suspenders: also honor our own expires_at in case the
	// transient's own expiry and this value ever drift (e.g. after a
	// persistent-object-cache flush that preserves the DB fallback row).
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
	$bundle = [
		'access_token' => $access_token,
		'instance_url' => $instance_url,
		'expires_at'   => time() + TOKEN_TTL_SECONDS,
	];

	set_transient( TRANSIENT_KEY, $bundle, TOKEN_TTL_SECONDS );
}

/**
 * Clears the cached token, forcing the next authenticate() call to fetch a
 * fresh one. Used when Salesforce reports the cached token is no longer
 * valid (INVALID_SESSION_ID).
 *
 * @return void
 */
function clear_cached_token() {
	delete_transient( TRANSIENT_KEY );
}
