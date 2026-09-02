<?php
/**
 * Builds the sponsor SOQL query.
 *
 * The WHERE clause here is reverse-engineered from Salesforce report
 * 00OPZ00000DSuhJ2AT via the existing app's
 * lib/salesforce/client.ts (commonMeetingDataWhere() / getMeetingDataSponsors()).
 * That report-derived logic is actively evolving upstream — keep it isolated
 * to this file, and if it changes, change it only here so the rest of the
 * plugin never has to know why a given row is or isn't a sponsor.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\Salesforce\QueryBuilders;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Tentative event-code format: letters, digits, underscore, period, hyphen.
// @todo confirm the real event-code format against Salesforce documentation
// (handoff doc question #6) before relying on this in production.
const EVENT_CODE_PATTERN = '/^[A-Za-z0-9_.-]{1,64}$/';

// Discount codes that mark an Opportunity as test/placeholder data.
const EXCLUDED_DISCOUNT_CODES = [ 'JUSTTESTING', 'DOLLARTEST', 'ONEDOLLARTEST' ];

// Account-name substrings that mark an internal/test account. Matching is
// case-insensitive (SOQL LIKE).
const EXCLUDED_ACCOUNT_NAME_FRAGMENTS = [ 'Test', 'Testing', 'SocialMedia', 'Assemble' ];

// Minimal field list — only what a sponsor-company choice needs, unlike the
// existing app's much wider MEETING_DATA_FIELDS.
const SPONSOR_SELECT_FIELDS = [ 'Id', 'Delegate__r.AccountId', 'Delegate__r.Account.Name' ];

/**
 * Validates a configured event code against the (tentative) allowed
 * character format. Validation, not escaping, is the primary defense
 * against SOQL injection here — escaping apostrophes alone is not enough.
 *
 * @param string $event_code Event code to validate.
 * @return bool
 */
function is_valid_event_code( $event_code ) {
	return is_string( $event_code ) && 1 === preg_match( EVENT_CODE_PATTERN, $event_code );
}

/**
 * Escapes a value for safe interpolation inside a single-quoted SOQL string
 * literal. Defense-in-depth only — callers must still validate the value
 * (e.g. via is_valid_event_code()) rather than relying on escaping alone.
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
 * Builds the WHERE-clause fragment shared by every meeting-data role query,
 * matching the existing app's commonMeetingDataWhere().
 *
 * @param string $safe_event_code Already-validated and SOQL-escaped event code.
 * @return string A `cond1 AND cond2 AND ...` SOQL fragment.
 */
function common_where( $safe_event_code ) {
	// Every excluded-name fragment becomes its own NOT LIKE condition.
	$account_not_contain = implode(
		' AND ',
		array_map(
			function ( $fragment ) {
				return "(NOT Delegate__r.Account.Name LIKE '%" . $fragment . "%')";
			},
			EXCLUDED_ACCOUNT_NAME_FRAGMENTS
		)
	);

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
 * Builds the full sponsor-specific WHERE clause: the shared filters plus the
 * RecordType/StageName conditions that define "sponsor", matching the
 * existing app's getMeetingDataSponsors().
 *
 * @param string $safe_event_code Already-validated and SOQL-escaped event code.
 * @return string
 */
function sponsor_where( $safe_event_code ) {
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
 * Builds the complete sponsor SOQL query for one event, validating the event
 * code first.
 *
 * @param string $event_code Conference/event code, as configured on the Gravity Forms form.
 * @return string|\WP_Error The SOQL statement, or a WP_Error if the event code fails validation.
 */
function build_sponsor_query( $event_code ) {
	if ( ! is_valid_event_code( $event_code ) ) {
		return new \WP_Error(
			'sfgf_invalid_event_code',
			__( 'The configured Salesforce event code contains unexpected characters.', 'salesforce-gravity-forms' )
		);
	}

	$safe_event_code = escape_soql_string( $event_code );

	return sprintf(
		'SELECT %s FROM Attendee__c WHERE %s ORDER BY Delegate__r.Account.Name ASC',
		implode( ', ', SPONSOR_SELECT_FIELDS ),
		sponsor_where( $safe_event_code )
	);
}
