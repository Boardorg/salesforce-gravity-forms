<?php
/**
 * Populates every Checkbox field using the Salesforce sponsors source with
 * live sponsor choices via the stable input registry.
 *
 * Hooked to every context Gravity Forms can render or process a form in.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\GravityForms\DynamicChoices;

// Set our aliases.
use SalesforceGravityForms\Config;
use SalesforceGravityForms\GravityForms\FormSettings;
use SalesforceGravityForms\GravityForms\FieldSettings;
use SalesforceGravityForms\Cache\CheckboxRegistry;
use SalesforceGravityForms\Providers\ChoiceProvider;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Start our engines.
add_filter( 'gform_pre_render', __NAMESPACE__ . '\populate_sponsor_fields' );
add_filter( 'gform_pre_validation', __NAMESPACE__ . '\populate_sponsor_fields' );
add_filter( 'gform_pre_submission_filter', __NAMESPACE__ . '\populate_sponsor_fields' );
add_filter( 'gform_admin_pre_render', __NAMESPACE__ . '\populate_sponsor_fields' );
add_filter( 'gform_form_pre_process_async_task', __NAMESPACE__ . '\populate_sponsor_fields_for_async_task', 10, 2 );

/**
 * Shared entry point for every hook except the async task one.
 *
 * @param array $form Form Object.
 * @return array The same Form Object, with sponsor fields populated.
 */
function populate_sponsor_fields( $form ) {
	return populate_form( $form, null );
}

/**
 * Entry point for background/async feed processing, which hands us the
 * entry directly instead of relying on $_POST or a wp-admin request.
 *
 * @param array $form  Form Object.
 * @param array $entry Entry being processed.
 * @return array The same Form Object, with sponsor fields populated.
 */
function populate_sponsor_fields_for_async_task( $form, $entry ) {
	return populate_form( $form, $entry );
}

/**
 * Finds every Checkbox field using the Salesforce sponsors source and
 * populates each one.
 *
 * @param array      $form  Form Object.
 * @param array|null $entry Entry to check for existing selections, when one was handed to us directly.
 * @return array The same Form Object.
 */
function populate_form( $form, $entry ) {

	// Nothing to do without a configured event code.
	$event_code = rgar( $form, FormSettings\EVENT_CODE_PROPERTY );
	if ( empty( $event_code ) ) {
		return $form;
	}

	// Get the form ID for registry lookups.
	$form_id = rgar( $form, 'id' );

	// Loop through every field and populate the ones using the Salesforce source.
	foreach ( $form['fields'] as $field ) {

		// Only Checkbox fields opted into the Salesforce sponsors source.
		if ( 'checkbox' !== $field->type || FieldSettings\SPONSORS_SOURCE_VALUE !== rgar( $field, FieldSettings\CHOICE_SOURCE_PROPERTY ) ) {
			continue;
		}

		// Populate this field's choices and inputs from the live sponsor list and the stable registry.
		populate_field( $field, $form_id, $event_code, $entry );
	}

	// Return the form with all sponsor fields populated.
	return $form;
}

/**
 * Populates one field's choices and inputs from the live sponsor list and
 * the stable registry.
 *
 * @param \GF_Field  $field      The Checkbox field to populate.
 * @param int        $form_id    Gravity Forms form ID.
 * @param string     $event_code Conference/event code.
 * @param array|null $entry      Entry to check for existing selections, when one was handed to us directly.
 * @return void
 */
function populate_field( $field, $form_id, $event_code, $entry ) {

	// Get the field ID for registry lookups.
	$field_id = $field->id;

	// If Salesforce is unreachable and there's no stale fallback, mark the field unavailable rather than guess.
	$choices_or_error = get_cached_choices( $event_code );
	if ( is_wp_error( $choices_or_error ) ) {
		mark_field_unavailable( $field );
		return;
	}

	// Set the live choices to the cached choices.
	$live_choices = $choices_or_error;

	// Sync the registry with the live list, then persist it.
	$registry = CheckboxRegistry\get_registry( $form_id, $field_id, $event_code );
	$registry = CheckboxRegistry\sync_registry( $registry, $live_choices );
	CheckboxRegistry\save_registry( $form_id, $field_id, $event_code, $registry );

	// Add every currently active sponsor in the provider's already-sorted order.
	$include_ids = wp_list_pluck( $live_choices, 'value' );

	// Loop through this entry's already-selected sponsors and add any not already included.
	foreach ( get_selected_account_ids( $field, $registry, $entry ) as $account_id ) {
		if ( ! in_array( $account_id, $include_ids, true ) ) {
			$include_ids[] = $account_id;
		}
	}

	// Build the choices and inputs for this field, in the order of $include_ids.
	list( $choices, $inputs ) = CheckboxRegistry\build_choices_and_inputs( $field_id, $registry, $include_ids );

	// Set the field object's choices and inputs to the ones we built.
	$field->choices = $choices;
	$field->inputs  = $inputs;
}

/**
 * Loads the sponsor choices for one event code.
 *
 * @param string $event_code Conference/event code.
 * @return array|\WP_Error
 */
function get_cached_choices( $event_code ) {

	// Use a static cache so we don't call the provider multiple times in one request.
	static $cache = [];

	// If we haven't already cached this event code, call the provider and cache it.
	if ( ! array_key_exists( $event_code, $cache ) ) {
		$cache[ $event_code ] = ChoiceProvider\get_choices( 'sponsors', $event_code );
	}

	// Return the cached choices for this event code.
	return $cache[ $event_code ];
}

/**
 * Finds which Account IDs are already checked for this field.
 *
 * @param \GF_Field  $field    The Checkbox field.
 * @param array      $registry Current registry.
 * @param array|null $entry    Entry to check, when one was handed to us directly.
 * @return string[] Selected Account IDs.
 */
function get_selected_account_ids( $field, $registry, $entry ) {

	// Get the field ID for registry lookups.
	$field_id = $field->id;

	// If handed an entry directly (async task processing), return its matches.
	if ( is_array( $entry ) ) {
		return match_registry_to_values( $field_id, $registry, $entry, false );
	}

	// Otherwise, check the raw submission and return its matches.
	$from_post = match_registry_to_values( $field_id, $registry, $_POST, true ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only, no state change.
	if ( ! empty( $from_post ) ) {
		return $from_post;
	}

	// Otherwise, ask Gravity Forms' own dynamic-population API what this
	// field's value should be.
	if ( $field->allowsPrepopulate && class_exists( 'GFFormsModel' ) ) {
		$prepopulated = \GFFormsModel::get_parameter_value( $field->inputName, [], $field );
		if ( ! empty( $prepopulated ) ) {

			// The value can be a comma-separated string or an array; normalize to an array.
			$values = is_array( $prepopulated ) ? $prepopulated : explode( ',', $prepopulated );
			$values = array_map( 'trim', $values );

			// Only ones the registry actually recognizes as Account IDs.
			return array_values( array_intersect( $values, array_keys( $registry ) ) );
		}
	}

	// Otherwise, check a loaded wp-admin entry-edit screen.
	$entry_id = absint( rgget( 'lid' ) );
	if ( $entry_id > 0 && class_exists( 'GFAPI' ) ) {

		// Load the entry and return its matches.
		$loaded_entry = \GFAPI::get_entry( $entry_id );
		if ( ! is_wp_error( $loaded_entry ) ) {
			return match_registry_to_values( $field_id, $registry, $loaded_entry, false );
		}
	}

	// If we got here, we couldn't find any selected Account IDs, so return an empty array.
	return [];
}

/**
 * Checks each registry entry's stable key against a set of submitted
 * values and returns the Account IDs that matched.
 *
 * @param int   $field_id  Gravity Forms field ID.
 * @param array $registry  Current registry.
 * @param array $values    $_POST, an Entry Object, or an Entry-shaped array.
 * @param bool  $is_post   True if $values is $_POST (underscore-separated keys), false for an entry (dot-separated keys).
 * @return string[] Matched Account IDs.
 */
function match_registry_to_values( $field_id, $registry, $values, $is_post ) {

	// Initialize the array we'll return.
	$selected = [];

	// Loop through the registry.
	foreach ( $registry as $account_id => $entry_data ) {

		// Build the key that would be used in $values for this registry entry, depending on whether it's a $_POST or an entry.
		$key = $is_post
			? 'input_' . $field_id . '_' . $entry_data['suffix']
			: $field_id . '.' . $entry_data['suffix'];

		// If the key exists in $values and its value matches this Account ID, add it to the selected list.
		if ( isset( $values[ $key ] ) && $account_id === $values[ $key ] ) {
			$selected[] = $account_id;
		}
	}

	// Return the list of selected Account IDs.
	return $selected;
}

/**
 * Empties a field's choices and shows an administrator-configurable
 * message, instead of guessing at a fallback list. If the field is
 * required, this message replaces Gravity Forms' generic "required" error.
 *
 * @param \GF_Field $field The field to mark unavailable.
 * @return void
 */
function mark_field_unavailable( $field ) {

	// Get the message to show in place of the field's choices.
	$message = get_unavailable_message();

	// Empty the field's choices and inputs, set the error message, and append the message to the description.
	$field->choices      = [];
	$field->inputs       = [];
	$field->errorMessage = $message;
	$field->description  = trim( $message . ( $field->description ? ' ' . $field->description : '' ) );
}

/**
 * Returns the message shown in place of an unavailable field's choices:
 * the admin-configured one (Settings → Salesforce) if set, otherwise a
 * default. Also filterable for anything the settings screen can't cover.
 *
 * @return string
 */
function get_unavailable_message() {

	// If the admin has configured a message, use it; otherwise use the default.
	$configured = Config\get_unavailable_message_override();
	$default    = '' !== $configured ? $configured : __( 'Sponsor list is temporarily unavailable. Please try again shortly.', 'salesforce-gravity-forms' );

	// Allow the message to be filtered for anything the settings screen can't cover.
	return apply_filters( 'sfgf_unavailable_message', $default );
}
