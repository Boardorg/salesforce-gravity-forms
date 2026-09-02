<?php
/**
 * Shared logging and cache-locking helpers.
 *
 * @package BoardMCSalesforceGravityForms
 */

// Declare our namespace.
namespace BoardMC\SalesforceGravityForms\Helpers\Utilities;

// Alias the root namespace for shared plugin-level constants.
use BoardMC\SalesforceGravityForms as Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Cache group used for the short stampede-prevention locks. A dedicated
// group keeps these keys out of any other plugin's default-group traffic.
const LOCK_CACHE_GROUP = 'boardmc_sfgf_locks';

/**
 * Adds this plugin to the list of plugins Gravity Forms' logging screen can
 * enable logging for. Registered on the `gform_logging_supported` filter.
 *
 * @param array $plugins Slug => label pairs of loggable plugins.
 * @return array The filtered list, with this plugin added.
 */
function register_logging_support( $plugins ) {
	$plugins[ Core\SLUG ] = 'BoardMC Salesforce Gravity Forms';
	return $plugins;
}

/**
 * Logs a message through Gravity Forms' logging system when available and
 * enabled, falling back to the PHP error log otherwise.
 *
 * Callers are responsible for keeping $message and $context free of
 * secrets, tokens, authorization headers, or personal data — this function
 * does not sanitize its input.
 *
 * @param string $level   One of 'error' or 'debug'.
 * @param string $message Sanitized, human-readable log message.
 * @param array  $context Optional structured context (counts, codes, ids — never secrets).
 * @return void
 */
function log( $level, $message, $context = [] ) {
	// Fold structured context into the message; GF logging takes a single string.
	$full_message = $context ? $message . ' ' . wp_json_encode( $context ) : $message;

	// Prefer Gravity Forms' own logging system when it has been loaded.
	if ( class_exists( '\GFLogging' ) ) {
		\GFLogging::include_logger();
		$message_type = 'error' === $level ? \KLogger::ERROR : \KLogger::DEBUG;
		\GFLogging::log_message( Core\SLUG, $full_message, $message_type );
		return;
	}

	// Fallback: plain PHP error log when Gravity Forms logging isn't available.
	error_log( sprintf( '[%s] [%s] %s', Core\SLUG, $level, $full_message ) );
}

/**
 * Attempts to acquire a short-lived, best-effort lock using the persistent
 * object cache's atomic "add" semantics (a VIP environment always runs a
 * persistent object cache, so this is race-safe there; on a plain
 * non-persistent cache it degrades to a non-atomic but harmless no-op guard).
 *
 * @param string $key         Lock key, unique per resource being guarded.
 * @param int    $ttl_seconds How long the lock is held before it expires on its own.
 * @return bool True if the lock was acquired, false if another request already holds it.
 */
function acquire_lock( $key, $ttl_seconds = 10 ) {
	// wp_cache_add() only succeeds if the key is not already set, giving us
	// an atomic "acquire if free" primitive without a dedicated locking API.
	return wp_cache_add( $key, 1, LOCK_CACHE_GROUP, $ttl_seconds );
}

/**
 * Releases a lock acquired via acquire_lock().
 *
 * @param string $key Lock key to release.
 * @return void
 */
function release_lock( $key ) {
	wp_cache_delete( $key, LOCK_CACHE_GROUP );
}

/**
 * Blocks briefly for another request's in-flight work to finish, re-checking
 * a condition callback between short sleeps instead of holding a lock open.
 * Used when a lock is already held so this request can use whatever the
 * lock-holder produces instead of duplicating the same Salesforce call.
 *
 * @param callable $is_ready       Returns a non-null result once ready, or null to keep waiting.
 * @param int      $max_wait_ms    Maximum total time to wait, in milliseconds.
 * @param int      $interval_ms    Time to sleep between checks, in milliseconds.
 * @return mixed The first non-null result from $is_ready, or null on timeout.
 */
function wait_for( $is_ready, $max_wait_ms = 2000, $interval_ms = 200 ) {
	$elapsed_ms = 0;
	while ( $elapsed_ms < $max_wait_ms ) {
		$result = $is_ready();
		if ( null !== $result ) {
			return $result;
		}
		usleep( $interval_ms * 1000 );
		$elapsed_ms += $interval_ms;
	}
	return null;
}
