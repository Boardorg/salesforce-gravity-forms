<?php
/**
 * Turns raw Salesforce sponsor rows into a deduplicated list of sponsor
 * companies.
 *
 * The choice value is the Account ID (Delegate__r.AccountId) and the label
 * is the Account name (Delegate__r.Account.Name). Both are kept on purpose:
 * the ID stays the stored submission value, and anything downstream that
 * needs the readable name can use the label.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\Providers\SalesforceSponsorProvider;

// Set our aliases.
use SalesforceGravityForms\Salesforce\SponsorRecords;
use SalesforceGravityForms\Helpers\Utilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Builds the sponsor choice list for one event.
 *
 * @param string $event_code Conference/event code.
 * @return array|\WP_Error Choices, each shaped like:
 *     { value, label, source_id, active, metadata: { attendee_ids } }
 */
function get_choices( $event_code ) {

	// Fetch the raw sponsor rows and bail on error.
	$records = SponsorRecords\get_raw_sponsor_records( $event_code );
	if ( is_wp_error( $records ) ) {
		return $records;
	}

	// Group rows into one choice per company.
	list( $companies, $skipped_count ) = group_by_account( $records );

	// Log the count of skipped rows, if any.
	if ( $skipped_count > 0 ) {
		Utilities\log(
			'error',
			'Skipped malformed sponsor rows missing an Account Id or Account name',
			[ 'event_code' => $event_code, 'skipped_count' => $skipped_count ]
		);
	}

	// Sort the choices by label and return them.
	return sort_choices( array_values( $companies ) );
}

/**
 * Groups sponsor rows into one choice per company (by Account ID),
 * collecting each row's Attendee__c ID along the way.
 *
 * @param array<int, array<string, mixed>> $records Raw Salesforce rows.
 * @return array{0: array<string, array>, 1: int} The choices, keyed by Account ID, and a count of skipped rows.
 */
function group_by_account( $records ) {

	// Build a map of Account ID => choice, and count how many rows we skip.
	$companies     = [];
	$skipped_count = 0;

	// Loop through the raw rows and build one choice per company.
	foreach ( $records as $record ) {
		$account_id   = $record['Delegate__r']['AccountId'] ?? null;
		$account_name = $record['Delegate__r']['Account']['Name'] ?? null;
		$attendee_id  = $record['Id'] ?? null;

		// Skip rows missing an Account ID or name.
		if ( empty( $account_id ) || empty( $account_name ) ) {
			$skipped_count++;
			continue;
		}

		// Key by Account ID, not name because two companies can share a name.
		if ( ! isset( $companies[ $account_id ] ) ) {
			$companies[ $account_id ] = [
				'value'     => $account_id,
				'label'     => $account_name,
				'source_id' => $account_id,
				'active'    => true,
				'metadata'  => [ 'attendee_ids' => [] ],
			];
		}

		// Always use the latest name seen for this company.
		$companies[ $account_id ]['label'] = $account_name;

		// Collect the Attendee__c ID for this row, if present.
		if ( null !== $attendee_id ) {
			$companies[ $account_id ]['metadata']['attendee_ids'][] = $attendee_id;
		}
	}

	// Return the map of companies and the count of skipped rows.
	return [ $companies, $skipped_count ];
}

/**
 * Sorts choices by label, case-insensitively. This only affects display order.
 *
 * @param array $choices Choices to sort.
 * @return array The same choices, sorted by label.
 */
function sort_choices( $choices ) {
	usort(
		$choices,
		function ( $a, $b ) {
			return strcasecmp( $a['label'], $b['label'] );
		}
	);

	return $choices;
}
