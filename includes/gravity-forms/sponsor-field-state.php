<?php
/**
 * Keeps each sponsor checkbox tied to the right sponsor.
 *
 * When Gravity Forms draws a Checkbox field it numbers the boxes by position:
 * the first box is 39.1, the second 39.2, and so on. dynamic-choices.php,
 * however, gives every sponsor a permanent number from the registry (39.5,
 * 39.51, 39.8 ...) so a sponsor keeps the same number as the live list
 * changes. The two only agree while the list is exactly what it was the first
 * time it was fetched. Once a sponsor is added or removed they drift apart and
 * Gravity Forms reads each box back under the wrong number, which means:
 *
 * - Picks saved earlier are not ticked again on the Networking Profile Form
 *   Edit URL, or after Next / Previous (the form is drawn again each time).
 * - Some picks never reach the entry, and so never reach Salesforce.
 *
 * This file puts everything under the registry number:
 *
 * 1. Each box is named, and ticked, by its registry number.
 * 2. Each posted value is checked against the sponsor it was posted for.
 *    Gravity Forms' own choice check (validateState) is switched off for
 *    these fields in dynamic-choices.php because it also numbers by position.
 * 3. Easy Passthrough is handed the saved picks straight from the saved
 *    entry. Left alone it reads them through the form as stored in the
 *    database, which holds an old copy of the sponsor list.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\GravityForms\SponsorFieldState;

// Set our aliases.
use SalesforceGravityForms\GravityForms\FieldSettings;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Start our engines.
add_filter( 'gform_field_choice_markup_pre_render', __NAMESPACE__ . '\align_choice_markup', 10, 4 );
add_filter( 'gform_field_validation', __NAMESPACE__ . '\validate_sponsor_picks', 10, 4 );
add_filter( 'gpep_field_values', __NAMESPACE__ . '\restore_passthrough_picks', 10, 4 );

/**
 * Names and ticks one sponsor box by its registry number.
 *
 * @param string    $markup The box and its label, as Gravity Forms drew them.
 * @param array     $choice The choice being drawn.
 * @param \GF_Field $field  The field the choice belongs to.
 * @param mixed     $value  What Gravity Forms is filling the field with: the saved picks (Edit URL) or the posted ones, keyed by input number.
 * @return string The markup, with the box named and ticked by its registry number.
 */
function align_choice_markup( $markup, $choice, $field, $value ) {

	// Only the sponsor boxes, and not in the form editor where they are disabled previews.
	if ( ! is_sponsor_field( $field ) || $field->is_form_editor() ) {
		return $markup;
	}

	// Find this sponsor's registry input and leave the box alone if it has none.
	$input_id = get_input_id_for_choice( $field, $choice );
	if ( '' === $input_id ) {
		return $markup;
	}

	// Name the box after its registry input so what is posted is read back from the same place.
	$renamed = preg_replace( '/(<input\b[^>]*?\bname=)([\'"])input_\d+\.\d+\2/', '${1}${2}input_' . $input_id . '${2}', $markup, 1, $count );
	if ( 1 !== $count || ! is_string( $renamed ) ) {
		return $markup;
	}

	// Tick the box when the value in hand holds this sponsor under its own input.
	$is_ticked = is_array( $value ) && '' !== (string) rgar( $choice, 'value' ) && (string) rgar( $value, $input_id ) === (string) rgar( $choice, 'value' );
	if ( $is_ticked && false === strpos( $renamed, "checked='checked'" ) ) {
		$renamed = preg_replace( '/(<input\b[^>]*?\btype=([\'"])checkbox\2)/', '${1} checked=\'checked\'', $renamed, 1 );
	}

	// Return the box.
	return $renamed;
}

/**
 * Rejects a sponsor value that was not posted for its own sponsor.
 *
 * @param array     $result The validation result so far: is_valid and message.
 * @param mixed     $value  The posted value, keyed by input number.
 * @param array     $form   The form being validated.
 * @param \GF_Field $field  The field being validated.
 * @return array The validation result.
 */
function validate_sponsor_picks( $result, $value, $form, $field ) {

	// Only the sponsor boxes, and only when something was posted.
	if ( ! is_sponsor_field( $field ) || ! is_array( $value ) || ! is_array( $field->inputs ) ) {
		return $result;
	}

	// Each input may only carry the Account Id of its own sponsor, or nothing.
	foreach ( $field->inputs as $index => $input ) {
		$posted = rgar( $value, (string) rgar( $input, 'id' ) );
		if ( '' === (string) $posted ) {
			continue;
		}

		// A value that isn't this input's sponsor means the form was tampered with.
		if ( (string) $posted !== (string) rgar( rgar( $field->choices, $index ), 'value' ) ) {
			$result['is_valid'] = false;
			$result['message']  = $field->get_state_validation_message();
			return $result;
		}
	}

	// Every posted value belongs to its sponsor.
	return $result;
}

/**
 * Hands Easy Passthrough the sponsors saved in the entry, whatever input
 * number each one was saved under.
 *
 * Easy Passthrough reads a checkbox through the form as stored in the
 * database, so it only finds the picks saved under a number that old copy
 * knows about. The Account Ids themselves are all we need, so they are read
 * straight from the saved entry.
 *
 * @param array $field_values The values Easy Passthrough prepared, keyed by field ID.
 * @param int   $form_id      The form being filled in.
 * @param array $mapping      The field mapping.
 * @param array $source_entry The saved entry the values come from.
 * @return array The values, with the saved sponsors restored.
 */
function restore_passthrough_picks( $field_values, $form_id, $mapping, $source_entry ) {

	// Nothing to restore without values and a saved entry from this form.
	if ( ! is_array( $field_values ) || ! is_array( $source_entry ) || (int) rgar( $source_entry, 'form_id' ) !== (int) $form_id ) {
		return $field_values;
	}

	// Get the form so we can find its sponsor fields.
	$form = \GFAPI::get_form( $form_id );
	if ( ! is_array( $form ) || empty( $form['fields'] ) ) {
		return $field_values;
	}

	// Loop through the sponsor fields that Easy Passthrough is filling in.
	foreach ( $form['fields'] as $field ) {
		if ( ! is_sponsor_field( $field ) || ! array_key_exists( $field->id, $field_values ) ) {
			continue;
		}

		// Swap in the picks saved in the entry, if it has any.
		$picks = get_saved_picks( $source_entry, $field->id );
		if ( ! empty( $picks ) ) {
			$field_values[ $field->id ] = $picks;
		}
	}

	// Return the values.
	return $field_values;
}

/**
 * Collects the sponsors saved in an entry for one field, under any input number.
 *
 * @param array $entry    The saved entry.
 * @param int   $field_id The sponsor field's ID.
 * @return string[] The Account Ids, without blanks or repeats.
 */
function get_saved_picks( $entry, $field_id ) {

	// Start with no picks.
	$picks = [];

	// An entry holds a checkbox as one key per input, such as "39.5".
	foreach ( $entry as $key => $value ) {

		// Skip every key that isn't one of this field's inputs.
		if ( ! is_numeric( $key ) || (int) $key !== (int) $field_id || ! is_string( $value ) ) {
			continue;
		}

		// Skip the inputs nothing was saved under.
		$value = trim( $value );
		if ( '' === $value ) {
			continue;
		}

		// Keep the Account Id.
		$picks[] = $value;
	}

	// Return the picks, once each.
	return array_values( array_unique( $picks ) );
}

/**
 * Finds the registry input that belongs to a choice. dynamic-choices.php
 * builds a choice and its input at the same position in their lists.
 *
 * @param \GF_Field $field  The sponsor field.
 * @param array     $choice The choice to find the input for.
 * @return string The input ID, such as "39.51", or an empty string when there isn't one.
 */
function get_input_id_for_choice( $field, $choice ) {

	// Nothing to match without both lists.
	if ( ! is_array( $field->choices ) || ! is_array( $field->inputs ) ) {
		return '';
	}

	// Find the choice by its Account Id and take the input at the same position.
	foreach ( $field->choices as $index => $candidate ) {
		if ( (string) rgar( $candidate, 'value' ) === (string) rgar( $choice, 'value' ) ) {
			return (string) rgar( rgar( $field->inputs, $index ), 'id' );
		}
	}

	// No such choice.
	return '';
}

/**
 * Checks whether a field is a Checkbox field populated from the Salesforce sponsors.
 *
 * @param mixed $field A form field.
 * @return bool
 */
function is_sponsor_field( $field ) {
	return is_object( $field )
		&& 'checkbox' === $field->type
		&& FieldSettings\SPONSORS_SOURCE_VALUE === rgar( $field, FieldSettings\CHOICE_SOURCE_PROPERTY );
}
