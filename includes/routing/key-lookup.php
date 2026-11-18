<?php
/**
 * Finds which conference a Networking Profile Key belongs to.
 *
 * The key (A-NP-0000000041) is unique to one attendee registration, and every
 * registration belongs to one Cvent event, so asking Salesforce for the key
 * answers "which event is this person attending?". The answer is the event's
 * Conference code (NALES, MDWS ...), the same vocabulary a form's Salesforce
 * Event Code uses.
 *
 * Results are cached: a hit for a day, a miss for five minutes, and a long-lived
 * copy that is only read when Salesforce can't be reached.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\Routing\KeyLookup;

// Set our aliases.
use SalesforceGravityForms\Config;
use SalesforceGravityForms\GravityForms\KeySanitizer;
use SalesforceGravityForms\Helpers\Utilities;
use SalesforceGravityForms\Salesforce\Client;
use SalesforceGravityForms\Salesforce\QueryBuilders;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// A key is "A-NP-" and digits, and nothing else. Stricter than the cleaner's
// pattern because this one decides what is allowed into a query.
const STRICT_KEY_PATTERN = '/^A-NP-\d{1,12}$/';

// How long each kind of answer is trusted.
const FOUND_TTL_SECONDS   = DAY_IN_SECONDS;
const MISSING_TTL_SECONDS = 5 * MINUTE_IN_SECONDS;
const STALE_TTL_SECONDS   = 30 * DAY_IN_SECONDS;

// Option holding the cache generation. Bumping it orphans every cached answer at
// once (the transients just expire on their own).
const GENERATION_OPTION = 'sfgf_key_lookup_generation';

/**
 * Cleans a key out of whatever arrived in the link.
 *
 * @param mixed $raw Text that should contain a key, e.g. "A-NP-0000000041>".
 * @return string The key in upper case, or '' when there isn't a usable one.
 */
function clean_key( $raw ) {

	// Only text can hold a key.
	if ( ! is_string( $raw ) ) {
		return '';
	}

	// Keep just the key, the same way the key cleaner does when an entry is saved.
	$key = KeySanitizer\extract_key( $raw );

	// What is left must be exactly a key.
	return ( is_string( $key ) && 1 === preg_match( STRICT_KEY_PATTERN, $key ) ) ? $key : '';
}

/**
 * Looks a key up: from the cache when it can, otherwise from Salesforce.
 *
 * @param string $key          A clean key (see clean_key()).
 * @param bool   $bypass_cache True to always ask Salesforce, and to report a failure as a failure instead of falling back
 *                             to an older answer (the admin test does, so it shows what Salesforce really says).
 * @return array|\WP_Error {
 *     @type string $status     'found' or 'missing'.
 *     @type string $conference The event's Conference code, '' when the attendee has no event or it has no code.
 *     @type bool   $stale      True when Salesforce was unreachable and an older answer was used.
 * }
 */
function lookup( $key, $bypass_cache = false ) {

	// Never let anything but a clean key near a query.
	if ( 1 !== preg_match( STRICT_KEY_PATTERN, (string) $key ) ) {
		return new \WP_Error( 'sfgf_bad_key', __( 'That is not a Networking Profile Key.', 'salesforce-gravity-forms' ) );
	}

	// A fresh answer needs no query.
	if ( ! $bypass_cache ) {
		$cached = read_cache( 'fresh', $key );
		if ( null !== $cached ) {
			return $cached;
		}
	}

	// Ask Salesforce.
	$records = Client\query( build_query( $key ) );

	// When Salesforce can't be reached, fall back to an older answer if there is one (never for a live check).
	if ( is_wp_error( $records ) ) {
		Utilities\log( 'error', 'Key lookup failed.', [ 'code' => $records->get_error_code() ] );

		$stale = $bypass_cache ? null : read_cache( 'stale', $key );
		if ( null !== $stale ) {
			$stale['stale'] = true;
			return $stale;
		}

		return $records;
	}

	// Remember the answer, and hand it back.
	$result = parse_records( $records );
	write_cache( $key, $result );
	return $result;
}

/**
 * The query that answers "which conference is this key for?". One row at most,
 * and only the one field, so the integration user needs as little access as it can.
 *
 * @param string $key A clean key.
 * @return string
 */
function build_query( $key ) {
	return sprintf(
		"SELECT CventEvents__Event__r.Conference__c FROM CventEvents__Attendee__c WHERE CventEvents_NP_Key__c = '%s' LIMIT 1",
		QueryBuilders\escape_soql_string( $key )
	);
}

/**
 * Turns the rows Salesforce returned into the answer lookup() hands back.
 *
 * @param array $records Rows from Client\query().
 * @return array{status: string, conference: string}
 */
function parse_records( $records ) {

	// No row: nobody has this key.
	if ( empty( $records ) || ! is_array( $records[0] ) ) {
		return [ 'status' => 'missing', 'conference' => '' ];
	}

	// An attendee with no event, or an event with no Conference code, has nothing to route by yet.
	$event      = $records[0]['CventEvents__Event__r'] ?? null;
	$conference = is_array( $event ) ? (string) ( $event['Conference__c'] ?? '' ) : '';

	// Only a value that looks like a Conference code counts.
	if ( ! QueryBuilders\is_valid_event_code( $conference ) ) {
		$conference = '';
	}

	return [ 'status' => 'found', 'conference' => strtoupper( $conference ) ];
}

/**
 * A fuller, uncached look at one key for the admin test: the attendee, the event,
 * the Conference code and year. Never used when a visitor opens a link.
 *
 * @param string $key A clean key.
 * @return array|\WP_Error
 */
function lookup_details( $key ) {

	// Never let anything but a clean key near a query.
	if ( 1 !== preg_match( STRICT_KEY_PATTERN, (string) $key ) ) {
		return new \WP_Error( 'sfgf_bad_key', __( 'That is not a Networking Profile Key.', 'salesforce-gravity-forms' ) );
	}

	$soql = sprintf(
		"SELECT Name, CventEvents__Event__r.Name, CventEvents__Event__r.Conference__c, CventEvents__Event__r.Conference_Year__c FROM CventEvents__Attendee__c WHERE CventEvents_NP_Key__c = '%s' LIMIT 1",
		QueryBuilders\escape_soql_string( $key )
	);

	// Ask Salesforce.
	$records = Client\query( $soql );
	if ( is_wp_error( $records ) ) {
		return $records;
	}

	// Nobody has this key.
	if ( empty( $records ) || ! is_array( $records[0] ) ) {
		return [ 'found' => false, 'soql' => $soql ];
	}

	// Pull out what the admin wants to see.
	$event = is_array( $records[0]['CventEvents__Event__r'] ?? null ) ? $records[0]['CventEvents__Event__r'] : [];

	return [
		'found'        => true,
		'soql'         => $soql,
		'attendee'     => (string) ( $records[0]['Name'] ?? '' ),
		'event_number' => (string) ( $event['Name'] ?? '' ),
		'conference'   => (string) ( $event['Conference__c'] ?? '' ),
		'year'         => (string) ( $event['Conference_Year__c'] ?? '' ),
	];
}

/**
 * Forgets every cached answer, so the next link of each key asks Salesforce again.
 *
 * @return void
 */
function clear_cache() {
	update_option( GENERATION_OPTION, get_generation() + 1, false );
}

/**
 * The current cache generation.
 *
 * @return int
 */
function get_generation() {
	return (int) get_option( GENERATION_OPTION, 0 );
}

/**
 * The transient key for one layer of one key's cached answer. The login URL
 * names the Salesforce org, so a sandbox answer is never used for production.
 *
 * @param string $layer 'fresh' or 'stale'.
 * @param string $key   A clean key.
 * @return string
 */
function build_cache_key( $layer, $key ) {
	return 'sfgf_key_' . $layer . '_' . md5( (string) Config\get_login_url() . '|' . get_generation() . '|' . $key );
}

/**
 * Reads one layer of the cache.
 *
 * @param string $layer 'fresh' or 'stale'.
 * @param string $key   A clean key.
 * @return array|null The cached answer, or null when there isn't one.
 */
function read_cache( $layer, $key ) {

	$cached = get_transient( build_cache_key( $layer, $key ) );

	// Anything that isn't an answer in the shape we store counts as nothing cached.
	if ( ! is_array( $cached ) || ! isset( $cached['status'], $cached['conference'] ) ) {
		return null;
	}

	return $cached;
}

/**
 * Caches an answer. A key with a Conference code is trusted for a day; anything
 * else (no such key, or no code yet) for five minutes, so a fix in Salesforce
 * shows up soon. Only a full answer is kept for the outage fallback.
 *
 * @param string $key    A clean key.
 * @param array  $result The answer from parse_records().
 * @return void
 */
function write_cache( $key, $result ) {

	// A full answer: the key exists and its event has a code.
	$is_full = 'found' === $result['status'] && '' !== $result['conference'];

	set_transient( build_cache_key( 'fresh', $key ), $result, $is_full ? FOUND_TTL_SECONDS : MISSING_TTL_SECONDS );

	if ( $is_full ) {
		set_transient( build_cache_key( 'stale', $key ), $result, STALE_TTL_SECONDS );
	}
}
