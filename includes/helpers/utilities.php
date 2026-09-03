<?php
/**
 * Shared logging and cache-locking helpers.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\Helpers\Utilities;

// Set our aliases.
use SalesforceGravityForms as Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Cache group for the stampede-prevention locks.
const LOCK_CACHE_GROUP = 'sfgf_locks';

/**
 * Adds this plugin to the list of plugins Gravity Forms' logging screen can
 * enable logging for.
 *
 * @param array $plugins Slug => label pairs of loggable plugins.
 * @return array The filtered list, with this plugin added.
 */
function register_logging_support( $plugins ) {

	// Add this plugin to the loggable list.
	$plugins[ Core\SLUG ] = 'Salesforce Gravity Forms';
	return $plugins;
}

/**
 * Logs through Gravity Forms' logging system when available, falling back
 * to the PHP error log.
 *
 * @param string $level   One of 'error' or 'debug'.
 * @param string $message Sanitized, human-readable log message.
 * @param array  $context Optional structured context (counts, codes, ids — never secrets).
 * @return void
 */
function log( $level, $message, $context = [] ) {

	// Fold the context into the message.
	$full_message = $context ? $message . ' ' . wp_json_encode( $context ) : $message;

	// Prefer Gravity Forms' logging when available.
	if ( class_exists( '\GFLogging' ) ) {
		\GFLogging::include_logger();
		$message_type = 'error' === $level ? \KLogger::ERROR : \KLogger::DEBUG;
		\GFLogging::log_message( Core\SLUG, $full_message, $message_type );
		return;
	}

	// Fallback: log to PHP's error log.
	error_log( sprintf( '[%s] [%s] %s', Core\SLUG, $level, $full_message ) ); // phpcs:ignore -- This is a fallback.
}

/**
 * Attempts to acquire a short-lived lock using the object cache's atomic add.
 *
 * @param string $key         Lock key, unique per resource being guarded.
 * @param int    $ttl_seconds How long the lock is held before it expires on its own.
 * @return bool True if the lock was acquired, false if another request already holds it.
 */
function acquire_lock( $key, $ttl_seconds = 10 ) {

	// Add the lock key only if it's not already set.
	return wp_cache_add( $key, 1, LOCK_CACHE_GROUP, $ttl_seconds ); // phpcs:ignore -- We defined the LOCK_CACHE_GROUP constant above.
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
 *
 * @param callable $is_ready       Returns a non-null result once ready, or null to keep waiting.
 * @param int      $max_wait_ms    Maximum total time to wait, in milliseconds.
 * @param int      $interval_ms    Time to sleep between checks, in milliseconds.
 * @return mixed The first non-null result from $is_ready, or null on timeout.
 */
function wait_for( $is_ready, $max_wait_ms = 2000, $interval_ms = 200 ) {

	// Poll until ready or the timeout elapses.
	$elapsed_ms = 0;
	while ( $elapsed_ms < $max_wait_ms ) {

		// Return as soon as the callback reports a result.
		$result = $is_ready();
		if ( null !== $result ) {
			return $result;
		}

		usleep( $interval_ms * 1000 );
		$elapsed_ms += $interval_ms;
	}

	// Timed out without a result.
	return null;
}
