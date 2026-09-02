<?php
/**
 * Converts raw Salesforce sponsor rows into deduplicated, normalized
 * sponsor-company choices.
 *
 * Mirrors the identity decision documented in the handoff: the choice
 * `value`/`source_id` is the sponsor's Salesforce Account ID
 * (Delegate__r.AccountId), and the `label` is the Account name
 * (Delegate__r.Account.Name) — never the Attendee__c row or the Contact.
 *
 * @package BoardMCSalesforceGravityForms
 */

// Declare our namespace.
namespace BoardMC\SalesforceGravityForms\Providers\SalesforceSponsorProvider;

use BoardMC\SalesforceGravityForms\Salesforce\SponsorRecords;
use BoardMC\SalesforceGravityForms\Helpers\Utilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Builds the normalized sponsor-company choice list for one event.
 *
 * @param string $event_code Conference/event code.
 * @return array|\WP_Error Normalized choices, each shaped like:
 *     { value, label, source_id, active, metadata: { attendee_ids } }
 */
function get_choices( $event_code ) {
	$records = SponsorRecords\get_raw_sponsor_records( $event_code );
	if ( is_wp_error( $records ) ) {
		return $records;
	}

	list( $companies, $skipped_count ) = group_by_account( $records );

	// Log a count only — never the rows themselves, which may carry
	// personal data via the joined Contact/Attendee relationship.
	if ( $skipped_count > 0 ) {
		Utilities\log(
			'error',
			'Skipped malformed sponsor rows missing an Account Id or Account name',
			[ 'event_code' => $event_code, 'skipped_count' => $skipped_count ]
		);
	}

	return sort_choices( array_values( $companies ) );
}

/**
 * Groups raw sponsor rows by Account ID, deduplicating multiple attendee
 * rows for the same company into a single choice and collecting every
 * matching Attendee__c.Id as diagnostic metadata.
 *
 * @param array<int, array<string, mixed>> $records Raw Salesforce rows.
 * @return array{0: array<string, array>, 1: int} The Account-ID-keyed choices, and a count of skipped malformed rows.
 */
function group_by_account( $records ) {
	$companies     = [];
	$skipped_count = 0;

	foreach ( $records as $record ) {
		$account_id   = $record['Delegate__r']['AccountId'] ?? null;
		$account_name = $record['Delegate__r']['Account']['Name'] ?? null;
		$attendee_id  = $record['Id'] ?? null;

		// Reject rows we cannot safely turn into an identifiable choice
		// rather than guessing at a fallback label or value.
		if ( empty( $account_id ) || empty( $account_name ) ) {
			$skipped_count++;
			continue;
		}

		// Deduplication key is the Account ID, never the Account name — two
		// different companies can share a display name.
		if ( ! isset( $companies[ $account_id ] ) ) {
			$companies[ $account_id ] = [
				'value'     => $account_id,
				'label'     => $account_name,
				'source_id' => $account_id,
				'active'    => true,
				'metadata'  => [ 'attendee_ids' => [] ],
			];
		}

		// A company-name change should update only the label; keep the
		// most recently seen one rather than the first.
		$companies[ $account_id ]['label'] = $account_name;

		if ( null !== $attendee_id ) {
			$companies[ $account_id ]['metadata']['attendee_ids'][] = $attendee_id;
		}
	}

	return [ $companies, $skipped_count ];
}

/**
 * Sorts choices by label, case-insensitively, for display only. Identity
 * (the `value`/`source_id`) is never derived from list position, so
 * reordering here can never change which Account ID a choice represents.
 *
 * @param array $choices Normalized choices.
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
