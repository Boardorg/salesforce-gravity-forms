<?php
/**
 * Builds the sponsor SOQL query.
 *
 * The WHERE clause is reverse-engineered from Salesforce report
 * 00OPZ00000DSuhJ2AT (see the existing app's client.ts). That logic is
 * still evolving -- keep changes isolated to this file.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\Salesforce\QueryBuilders;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Define the confirmed event-code format: letters, digits, underscore, period, hyphen.
const EVENT_CODE_PATTERN = '/^[A-Za-z0-9_.-]{1,64}$/';

// Define discount codes that mark an Opportunity as test/placeholder data.
const EXCLUDED_DISCOUNT_CODES = [ 'JUSTTESTING', 'DOLLARTEST', 'ONEDOLLARTEST' ];

// Define account-name substrings that mark an internal/test account (matched case-insensitively).
const EXCLUDED_ACCOUNT_NAME_FRAGMENTS = [ 'Test', 'Testing', 'SocialMedia', 'Assemble' ];

// Define the minimal field list a sponsor-company choice needs.
const SPONSOR_SELECT_FIELDS = [ 'Id', 'Delegate__r.AccountId', 'Delegate__r.Account.Name' ];

/**
 * Checks whether an event code matches the allowed character format. This
 * is the main defense against SOQL injection here, not escaping.
 *
 * @param string $event_code Event code to validate.
 * @return bool
 */
function is_valid_event_code( $event_code ) {
	return is_string( $event_code ) && 1 === preg_match( EVENT_CODE_PATTERN, $event_code );
}

/**
 * Escapes a value for a single-quoted SOQL string. Defense-in-depth only --
 * callers must still validate first.
 *
 * @param string $value Raw value to escape.
 * @return string
 */
function escape_soql_string( $value ) {
	return str_replace( "'", "\\'", $value );
}

/**
 * Formats an array of strings as a SOQL `IN (...)` value list.
 *
 * @param string[] $values Raw values to format.
 * @return string The joined `'a', 'b', 'c'` body for an `IN (...)` clause.
 */
function soql_string_list( $values ) {
	return implode(
		', ',
		array_map(
			function ( $value ) {
				return "'" . escape_soql_string( $value ) . "'";
			},
			$values
		)
	);
}

/**
 * Builds the WHERE-clause fragment shared by every sponsor query.
 *
 * @param string $safe_event_code Already-validated and SOQL-escaped event code.
 * @return string A `cond1 AND cond2 AND ...` SOQL fragment.
 */
function common_where( $safe_event_code ) {

	// Turn each excluded-name fragment into its own NOT LIKE condition.
	$account_not_contain = implode(
		' AND ',
		array_map(
			function ( $fragment ) {
				return "(NOT Delegate__r.Account.Name LIKE '%" . $fragment . "%')";
			},
			EXCLUDED_ACCOUNT_NAME_FRAGMENTS
		)
	);

	// Combine every condition with AND.
	return implode(
		' AND ',
		[
			'Delegate__c != NULL',
			'Delegate__r.AccountId != NULL',
			'Registration__r.Active_Conference__c = TRUE',
			'Registration__r.Discount_Code__c NOT IN (' . soql_string_list( EXCLUDED_DISCOUNT_CODES ) . ')',
			"Registration__r.Conference__c = '" . $safe_event_code . "'",
			$account_not_contain,
			"(Status__c = NULL OR Status__c = 'Pending Replacement')",
		]
	);
}

/**
 * Builds the full sponsor WHERE clause: the shared filters plus the
 * RecordType/StageName conditions that define a sponsor.
 *
 * @param string $safe_event_code Already-validated and SOQL-escaped event code.
 * @return string
 */
function sponsor_where( $safe_event_code ) {

	// Add the sponsor-specific conditions.
	return implode(
		' AND ',
		[
			common_where( $safe_event_code ),
			"Registration__r.RecordType.Name = 'Sponsor'",
			"Registration__r.StageName IN ('Closed-Won', 'Registered')",
		]
	);
}

/**
 * Builds the complete sponsor SOQL query for one event.
 *
 * @param string $event_code Conference/event code, as configured on the Gravity Forms form.
 * @return string|\WP_Error The SOQL statement, or a WP_Error if the event code fails validation.
 */
function build_sponsor_query( $event_code ) {

	// Bail if the event code doesn't match the allowed format.
	if ( ! is_valid_event_code( $event_code ) ) {
		return new \WP_Error(
			'sfgf_invalid_event_code',
			__( 'The configured Salesforce event code contains unexpected characters.', 'salesforce-gravity-forms' )
		);
	}

	// Escape the event code for safe interpolation.
	$safe_event_code = escape_soql_string( $event_code );

	// Build and return the full SOQL statement.
	return sprintf(
		'SELECT %s FROM Attendee__c WHERE %s ORDER BY Delegate__r.Account.Name ASC',
		implode( ', ', SPONSOR_SELECT_FIELDS ),
		sponsor_where( $safe_event_code )
	);
}
