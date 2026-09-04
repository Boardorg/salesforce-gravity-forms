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
add_filter( 'gform_form_pre_process_async_task', __NAMESPACE__ . '\populate_sponsor_fields' );

/**
 * Finds every Checkbox field using the Salesforce sponsors source and
 * populates each one with the live sponsor list.
 *
 * @param array $form Form Object.
 * @return array The same Form Object.
 */
function populate_sponsor_fields( $form ) {

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

		// Populate this field's choices and inputs from the live sponsor list.
		populate_field( $field, $form_id, $event_code );
	}

	// Return the form with all sponsor fields populated.
	return $form;
}

/**
 * Populates one field's choices and inputs from the live sponsor list and
 * the stable registry.
 *
 * @param \GF_Field $field      The Checkbox field to populate.
 * @param int       $form_id    Gravity Forms form ID.
 * @param string    $event_code Conference/event code.
 * @return void
 */
function populate_field( $field, $form_id, $event_code ) {

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

	// Only sponsors in the live list get a choice -- inactive ones
	// drop out of the field entirely, even if already selected.
	$account_ids = wp_list_pluck( $live_choices, 'value' );

	// Build the choices and inputs for this field, in the provider's sorted order.
	list( $choices, $inputs ) = CheckboxRegistry\build_choices_and_inputs( $field_id, $registry, $account_ids );

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
 * Empties a field's choices and shows a message to indicate the list is unavailable.
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
 * Returns the message shown in place of an unavailable field's choices. Configurable in the admin settings.
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
