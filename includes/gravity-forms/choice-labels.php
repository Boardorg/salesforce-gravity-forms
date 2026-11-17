<?php
/**
 * Sends the visible choice labels to Salesforce instead of the form's safe values.
 *
 * Some Networking Profile questions (current focus, systems and platforms,
 * meeting topics, funding initiatives) store a safe value for each choice,
 * such as current_focus_financial_planning, instead of the text the visitor
 * sees. The Salesforce add-on turns every comma in a list of picks into a
 * semicolon, so a label with a comma in it would be cut in two. A safe value
 * has no commas to cut. Salesforce's picklists only accept the visible label,
 * though, so just before the add-on sends an entry this swaps each safe value
 * back for its label.
 *
 * The fields to swap are worked out from the Salesforce feed's own field
 * mapping, so no form, field or Salesforce field names are listed here. Only a
 * choice whose value starts with one of SAFE_VALUE_PREFIXES is swapped.
 * Everything else (an "Other" answer, the sponsors' Account Ids, plain text)
 * goes through as it is.
 *
 * It runs for every Salesforce feed on every form, so entries sent again with
 * the Resend to Salesforce action are swapped too.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\GravityForms\ChoiceLabels;

// Set our aliases.
use SalesforceGravityForms\Helpers\Utilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// A choice value that starts with one of these is a safe value to swap for its label.
// A question whose safe values start with a different prefix needs that prefix added here.
const SAFE_VALUE_PREFIXES = [
	'current_focus_',
	'systems_platforms_',
	'meeting_topics_',
	'funding_initiatives_',
];

// Start our engines. The priority is the highest possible so this runs after
// every other filter on the hook and swaps the values that are about to be sent.
add_filter( 'gform_salesforce_object_data', __NAMESPACE__ . '\swap_values_for_labels', PHP_INT_MAX, 4 );

/**
 * Swaps safe choice values for their visible labels in the request body the
 * Salesforce add-on is about to send.
 *
 * @param array $body  Salesforce field names mapped to their values.
 * @param array $form  The form object.
 * @param array $entry The entry being sent.
 * @param array $feed  The Salesforce feed being processed.
 * @return array The same body, with each safe value swapped for its label.
 */
function swap_values_for_labels( $body, $form, $entry, $feed ) {

	// Nothing to do without a body, a form, and a feed to read the field mapping from.
	if ( ! is_array( $body ) || ! is_array( $form ) || ! is_array( $feed ) ) {
		return $body;
	}

	// The field mapping lives in the feed's settings.
	$feed_meta = rgar( $feed, 'meta' );
	if ( ! is_array( $feed_meta ) ) {
		return $body;
	}

	// Look at each value on its way to Salesforce.
	foreach ( $body as $salesforce_field => $value ) {

		// Only text can hold a safe value. Blanks and true/false values pass through.
		if ( ! is_string( $value ) || '' === $value ) {
			continue;
		}

		// The feed stores each mapping as "fieldMapping_" plus the Salesforce field's name, holding the form field's ID.
		$form_field_id = rgar( $feed_meta, 'fieldMapping_' . $salesforce_field );
		if ( empty( $form_field_id ) ) {
			continue;
		}

		// Find the label for each safe value on that form field, if it has any.
		$labels_by_value = get_labels_by_value( $form, $form_field_id );
		if ( empty( $labels_by_value ) ) {
			continue;
		}

		// Swap them. The value is left alone when it held no safe value.
		$swapped = swap_list( $value, $labels_by_value );
		if ( null === $swapped ) {
			continue;
		}

		$body[ $salesforce_field ] = $swapped;
		Utilities\log(
			'debug',
			'Swapped choice values for labels.',
			[
				'form_id'          => absint( rgar( $form, 'id' ) ),
				'form_field_id'    => (string) $form_field_id,
				'salesforce_field' => (string) $salesforce_field,
			]
		);
	}

	return $body;
}

/**
 * Maps each safe value on a form field to the label the visitor saw.
 *
 * @param array      $form     The form object.
 * @param string|int $field_id The ID of the form field the Salesforce field is mapped to.
 * @return array Safe value => label pairs. Empty when the field has no safe choices.
 */
function get_labels_by_value( $form, $field_id ) {

	// The field has to exist and offer choices.
	$field = \GFAPI::get_field( $form, $field_id );
	if ( ! $field || empty( $field->choices ) ) {
		return [];
	}

	$labels_by_value = [];
	foreach ( $field->choices as $choice ) {

		// A choice with no value or no label has nothing to swap. The label is stored with HTML entities, so decode it.
		$value = (string) rgar( $choice, 'value' );
		$label = html_entity_decode( (string) rgar( $choice, 'text' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( '' === $value || '' === $label ) {
			continue;
		}

		// Keep it when the value is one of our safe values.
		if ( is_safe_value( $value ) ) {
			$labels_by_value[ $value ] = $label;
		}
	}

	return $labels_by_value;
}

/**
 * Tells whether a choice value is one of the safe values this file swaps.
 *
 * @param string $value A choice value.
 * @return bool True when it starts with one of the safe value prefixes.
 */
function is_safe_value( $value ) {

	// Check each prefix in turn.
	foreach ( SAFE_VALUE_PREFIXES as $prefix ) {
		if ( str_starts_with( $value, $prefix ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Swaps the safe values in a list of picks for their labels.
 *
 * @param string $list            The picks as the add-on built them, such as "value_one; value_two".
 * @param array  $labels_by_value Safe value => label pairs.
 * @return string|null The labels joined with semicolons, or null when the list held no safe value.
 */
function swap_list( $list, $labels_by_value ) {

	// The add-on separates the picks with semicolons. Commas are the fallback in case a version hasn't converted them yet.
	$separator = false !== strpos( $list, ';' ) ? '/\s*;\s*/' : '/\s*,\s*/';
	$picks     = preg_split( $separator, $list, -1, PREG_SPLIT_NO_EMPTY );
	if ( ! is_array( $picks ) ) {
		return null;
	}

	$labels  = [];
	$swapped = false;
	foreach ( $picks as $pick ) {
		$pick = trim( $pick );

		// A safe value becomes its label. Anything else, such as an "Other" answer that was saved as its label, stays as it was.
		if ( isset( $labels_by_value[ $pick ] ) ) {
			$labels[] = $labels_by_value[ $pick ];
			$swapped  = true;
		} else {
			$labels[] = $pick;
		}
	}

	// Nothing was swapped, so the original value stands.
	if ( ! $swapped ) {
		return null;
	}

	// Salesforce separates the picks of a multi-select with semicolons. A comma inside a label is left alone.
	return implode( ';', $labels );
}
