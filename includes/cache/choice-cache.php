<?php
/**
 * Two-level cache for normalized Gravity Forms choice lists: a short-lived
 * "fresh" layer that drives normal rendering, and a longer-lived "stale"
 * layer that is only ever read when a live Salesforce query fails.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\Cache\ChoiceCache;

// Set our aliases.
use SalesforceGravityForms\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// TODO: Do we need multiple cache layers, or is a single layer with a longer TTL sufficient?

// Set 10-15 minutes for the fresh layer, 24 hours for the stale fallback layer.
const FRESH_TTL_SECONDS = 12 * MINUTE_IN_SECONDS;
const STALE_TTL_SECONDS = 24 * HOUR_IN_SECONDS;

/**
 * Builds the transient key for one cache layer, keyed by Salesforce
 * environment plus the choice source and event code.
 *
 * @param string $layer      'fresh' or 'stale'.
 * @param string $source     Choice source identifier (e.g. 'sponsors').
 * @param string $event_code Conference/event code the choices are scoped to.
 * @return string
 */
function build_key( $layer, $source, $event_code ) {

	// The login URL uniquely identifies which Salesforce org/environment
	// (production vs. sandbox) the cached choices came from.
	$environment = (string) Config\get_login_url();

	// Build a unique transient key for this cache layer, source, and event code.
	return 'sfgf_choices_' . $layer . '_' . md5( $environment . '|' . $source . '|' . $event_code );
}

/**
 * Reads the fresh-layer cache.
 *
 * @param string $source     Choice source identifier.
 * @param string $event_code Conference/event code.
 * @return array|null The cached choice list, or null on a miss.
 */
function get_fresh( $source, $event_code ) {
	$cached = get_transient( build_key( 'fresh', $source, $event_code ) );
	return false === $cached ? null : $cached;
}

/**
 * Writes the fresh-layer cache.
 *
 * @param string $source     Choice source identifier.
 * @param string $event_code Conference/event code.
 * @param array  $choices    Normalized choice list to cache.
 * @return void
 */
function set_fresh( $source, $event_code, $choices ) {
	set_transient( build_key( 'fresh', $source, $event_code ), $choices, FRESH_TTL_SECONDS );
}

/**
 * Reads the stale-layer fallback cache.
 *
 * @param string $source     Choice source identifier.
 * @param string $event_code Conference/event code.
 * @return array|null The cached choice list, or null if none has ever been stored.
 */
function get_stale( $source, $event_code ) {
	$cached = get_transient( build_key( 'stale', $source, $event_code ) );
	return false === $cached ? null : $cached;
}

/**
 * Writes the stale-layer fallback cache. Callers should only do this after a
 * successful live Salesforce query, never from a fallback read.
 *
 * @param string $source     Choice source identifier.
 * @param string $event_code Conference/event code.
 * @param array  $choices    Normalized choice list to cache.
 * @return void
 */
function set_stale( $source, $event_code, $choices ) {
	set_transient( build_key( 'stale', $source, $event_code ), $choices, STALE_TTL_SECONDS );
}
