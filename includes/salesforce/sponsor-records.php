<?php
/**
 * Retrieves raw sponsor rows from Salesforce.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\Salesforce\SponsorRecords;

// Set our aliases.
use SalesforceGravityForms\Salesforce\Client;
use SalesforceGravityForms\Salesforce\QueryBuilders;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Returns every Attendee__c row that counts as a sponsor for the given
 * event, with only the minimal fields a sponsor-company choice needs.
 *
 * @param string $event_code Conference/event code, as configured on the Gravity Forms form.
 * @return array<int, array<string, mixed>>|\WP_Error Raw Salesforce rows, or a WP_Error.
 */
function get_raw_sponsor_records( $event_code ) {

	// Build the SOQL and bail if the event code is invalid.
	$soql = QueryBuilders\build_sponsor_query( $event_code );
	if ( is_wp_error( $soql ) ) {
		return $soql;
	}

	// Run the query and return its records.
	return Client\query( $soql );
}
