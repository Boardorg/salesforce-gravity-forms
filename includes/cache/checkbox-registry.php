<?php
/**
 * Keeps a Checkbox field's Gravity Forms sub-input IDs stable as the live
 * Salesforce sponsor list changes.
 *
 * One registry per form/field/event-code combo, stored in wp_options and
 * keyed by Account ID:
 *   suffix     -- permanent Gravity Forms input suffix (e.g. the "1" in "12.1"), never reused
 *   label      -- latest known Account name
 *   active     -- still returned by the live Salesforce query
 *   first_seen -- when this Account ID first appeared
 *   last_seen  -- when this Account ID last appeared in a live query
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\Cache\CheckboxRegistry;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Builds the wp_options key for one form/field/event-code registry.
 *
 * @param int    $form_id    Gravity Forms form ID.
 * @param int    $field_id   Gravity Forms field ID.
 * @param string $event_code Conference/event code.
 * @return string
 */
function build_option_name( $form_id, $field_id, $event_code ) {
	return 'sfgf_registry_' . $form_id . '_' . $field_id . '_' . md5( $event_code );
}

/**
 * Reads the stored registry, or an empty one if none exists yet.
 *
 * @param int    $form_id    Gravity Forms form ID.
 * @param int    $field_id   Gravity Forms field ID.
 * @param string $event_code Conference/event code.
 * @return array<string, array{suffix: int, label: string, active: bool, first_seen: string, last_seen: string}>
 */
function get_registry( $form_id, $field_id, $event_code ) {
	$registry = get_option( build_option_name( $form_id, $field_id, $event_code ), [] );
	return is_array( $registry ) ? $registry : [];
}

/**
 * Persists the registry, non-autoloaded.
 *
 * @param int    $form_id    Gravity Forms form ID.
 * @param int    $field_id   Gravity Forms field ID.
 * @param string $event_code Conference/event code.
 * @param array  $registry   The registry to store.
 * @return void
 */
function save_registry( $form_id, $field_id, $event_code, $registry ) {
	update_option( build_option_name( $form_id, $field_id, $event_code ), $registry, false );
}

/**
 * Picks the next unused input suffix, skipping multiples of 10 -- Gravity
 * Forms reserves those for its own use when choices are reordered in the
 * form editor.
 *
 * @param array $registry Current registry.
 * @return int
 */
function next_suffix( $registry ) {

	// Set our max variable to start at 0.
	$max = 0;

	// Loop through the registry and find the highest suffix.
	foreach ( $registry as $entry ) {
		$max = max( $max, $entry['suffix'] );
	}

	// Set the next suffix to one higher than the max.
	$next = $max + 1;

	// Did we land on a multiple of 10?
	if ( 0 === $next % 10 ) {

		// Skip to the next non-multiple of 10.
		$next++;
	}

	// Return the next suffix.
	return $next;
}

/**
 * Merges a live Salesforce choice list into the registry.
 *
 * @param array $registry     Current registry.
 * @param array $live_choices Normalized choices from ChoiceProvider\get_choices().
 * @return array The updated registry.
 */
function sync_registry( $registry, $live_choices ) {

	// Get the current time in UTC for first_seen/last_seen.
	$now          = gmdate( 'c' );
	$live_account_ids = [];

	// Add new Account IDs and refresh known ones.
	foreach ( $live_choices as $choice ) {
		$account_id         = $choice['value'];
		$live_account_ids[] = $account_id;

		// If this Account ID isn't in the registry yet, add it with a new suffix and first_seen/last_seen timestamps.
		if ( ! isset( $registry[ $account_id ] ) ) {
			$registry[ $account_id ] = [
				'suffix'     => next_suffix( $registry ),
				'label'      => $choice['label'],
				'active'     => true,
				'first_seen' => $now,
				'last_seen'  => $now,
			];
			continue;
		}

		// Existing entry: update the label and activity, but never the suffix.
		$registry[ $account_id ]['label']     = $choice['label'];
		$registry[ $account_id ]['active']    = true;
		$registry[ $account_id ]['last_seen'] = $now;
	}

	// Anything in the registry that Salesforce didn't return this time becomes inactive.
	foreach ( $registry as $account_id => $entry ) {
		if ( ! in_array( $account_id, $live_account_ids, true ) ) {
			$registry[ $account_id ]['active'] = false;
		}
	}

	// Return the updated registry.
	return $registry;
}

/**
 * Builds a Gravity Forms `choices` and `inputs` array together from the
 * registry, in the given Account ID order. Each input's suffix always
 * comes from the registry, never from its position in the list.
 *
 * @param int    $field_id    Gravity Forms field ID.
 * @param array  $registry    Current registry.
 * @param array  $account_ids Account IDs to include, already in display order.
 * @return array{0: array, 1: array} [ choices, inputs ]
 */
function build_choices_and_inputs( $field_id, $registry, $account_ids ) {

	// Initialize the arrays we'll return.
	$choices = [];
	$inputs  = [];

	// Loop through the Account IDs in order and build a choice and input for each.
	foreach ( $account_ids as $account_id ) {

		// Skip anything not in the registry (shouldn't happen if sync_registry() ran first).
		if ( ! isset( $registry[ $account_id ] ) ) {
			continue;
		}

		// Get the registry entry for this Account ID.
		$entry = $registry[ $account_id ];

		// Build the choice and input, using the registry's suffix and label.
		$choices[] = [
			'text'  => $entry['label'],
			'value' => $account_id,
		];
		$inputs[] = [
			'id'    => $field_id . '.' . $entry['suffix'],
			'label' => $entry['label'],
		];
	}

	// Return the choices and inputs together.
	return [ $choices, $inputs ];
}
