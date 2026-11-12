<?php
/**
 * Keeps the Networking Profile Key clean.
 *
 * The key arrives from the personalized link (the "attendee" URL parameter)
 * and the Salesforce add-on uses it to find the attendee record. A stray
 * character on the end of it, such as the ">" some email clients leave on a
 * link, means no record matches and the whole submission silently fails to
 * reach Salesforce. This keeps only the key itself, at two points:
 *
 * 1. When the entry is saved, so the stored entry (and any edit link built
 *    from it) is clean.
 * 2. When the Salesforce add-on builds its request, so entries already saved
 *    with a stray character can be re-sent without editing them first.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\GravityForms\KeySanitizer;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// The "Parameter Name" on the key field (Allow field to be populated dynamically).
const KEY_PARAMETER_NAME = 'attendee';

// The Salesforce field the feed maps the key into.
const SALESFORCE_KEY_FIELD = 'CventEvents_NP_Key__c';

// What a key looks like inside whatever text arrived, e.g. A-NP-0000000380.
const KEY_PATTERN = '/A-NP-\d+/i';

// Start our engines.
add_filter( 'gform_save_field_value', __NAMESPACE__ . '\clean_saved_key', 10, 3 );
add_filter( 'gform_salesforce_object_data', __NAMESPACE__ . '\clean_outgoing_key' );

/**
 * Cleans the key field's value as the entry is saved.
 *
 * @param mixed     $value The value about to be saved.
 * @param array     $entry The entry being saved.
 * @param \GF_Field $field The field the value belongs to.
 * @return mixed The cleaned key, or the value untouched for any other field.
 */
function clean_saved_key( $value, $entry, $field ) {

	// Only the key field, identified by its parameter name.
	if ( KEY_PARAMETER_NAME !== rgar( $field, 'inputName' ) ) {
		return $value;
	}

	// Keep just the key.
	return extract_key( $value );
}

/**
 * Cleans the key in the request body the Salesforce add-on is about to send.
 *
 * @param array $body Salesforce field names mapped to their values.
 * @return array The same body, with a clean key.
 */
function clean_outgoing_key( $body ) {

	// Nothing to clean when the request has no key.
	if ( ! is_array( $body ) || ! isset( $body[ SALESFORCE_KEY_FIELD ] ) ) {
		return $body;
	}

	// Keep just the key.
	$body[ SALESFORCE_KEY_FIELD ] = extract_key( $body[ SALESFORCE_KEY_FIELD ] );

	return $body;
}

/**
 * Pulls the key out of a string that may have extra characters around it.
 *
 * @param mixed $value Text that should contain a key.
 * @return mixed The key in upper case, or the value untouched when it isn't text or holds no key.
 */
function extract_key( $value ) {

	// Leave anything that isn't plain text alone.
	if ( ! is_string( $value ) ) {
		return $value;
	}

	// Return the key itself when the text contains one.
	if ( preg_match( KEY_PATTERN, $value, $matches ) ) {
		return strtoupper( $matches[0] );
	}

	// No key in there (blank, or a different kind of value), so don't touch it.
	return $value;
}
