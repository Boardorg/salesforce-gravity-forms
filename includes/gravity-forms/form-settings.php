<?php
/**
 * Per-form Salesforce settings: the event code and the page that shows the form.
 *
 * Adds a "Salesforce Event Code" field to the form editor's Form Settings
 * screen. Any Checkbox field on the form can opt into the Salesforce
 * sponsor source (see field-settings.php); they all share this one
 * form-level event code. The Networking Profile router also uses it: an
 * attendee whose event has this Conference code is sent to this form.
 *
 * The second field, "Networking Profile Page", names the page the form is
 * shown on, so the router knows where to send those attendees.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\GravityForms\FormSettings;

// Set our aliases.
use SalesforceGravityForms\Routing\FormMap;
use SalesforceGravityForms\Salesforce\QueryBuilders;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Define the form-meta property names these settings are stored under.
const EVENT_CODE_PROPERTY = 'sfgfEventCode';
const PAGE_ID_PROPERTY    = 'sfgfPageId';

// Start our engines.
add_filter( 'gform_form_settings_fields', __NAMESPACE__ . '\add_form_settings_fields' );

/**
 * Adds the Salesforce Event Code and Networking Profile Page fields to a new settings section.
 *
 * @param array $fields Existing form settings sections/fields.
 * @return array The filtered sections/fields.
 */
function add_form_settings_fields( $fields ) {

	// Add a new section so these settings don't get lost among GF's own settings.
	$fields['salesforce'] = [
		'title'  => __( 'Salesforce Integration', 'salesforce-gravity-forms' ),
		'fields' => [
			[
				'name'                => EVENT_CODE_PROPERTY,
				'type'                => 'text',
				'label'               => __( 'Salesforce Event Code', 'salesforce-gravity-forms' ),
				'tooltip'             => __( 'Conference code used to look up this form\'s eligible sponsor companies. It is also how the Networking Profile router finds this form: attendees of an event with this Conference code are sent here. An administrator-set value only -- never taken from the page or a hidden field.', 'salesforce-gravity-forms' ),
				'validation_callback' => __NAMESPACE__ . '\validate_event_code_field',
			],
			[
				'name'                => PAGE_ID_PROPERTY,
				'type'                => 'select',
				'label'               => __( 'Networking Profile Page', 'salesforce-gravity-forms' ),
				'tooltip'             => __( 'The page this form is shown on. The Networking Profile router sends attendees of this form\'s event to it. Leave it on "The router page itself" only when this form is the one shown on the router page.', 'salesforce-gravity-forms' ),
				'choices'             => FormMap\get_page_choices(),
				'validation_callback' => __NAMESPACE__ . '\validate_page_field',
			],
		],
	];

	return $fields;
}

/**
 * Rejects a page that doesn't exist or isn't published, so the router never sends
 * anyone to a page that isn't there.
 *
 * @param \GF_Field $field The settings field instance.
 * @param string    $value The submitted page ID.
 * @return void
 */
function validate_page_field( $field, $value ) {

	// Blank means "the router page itself".
	if ( '' === trim( (string) $value ) ) {
		return;
	}

	// Otherwise it has to be a published page.
	if ( ! FormMap\is_published_page( absint( $value ) ) ) {
		$field->set_error( __( 'Choose a published page.', 'salesforce-gravity-forms' ) );
	}
}

/**
 * Rejects an event code that doesn't match the format QueryBuilders
 * actually accepts, so a typo is caught in the form editor rather than
 * silently producing an empty sponsor list later.
 *
 * @param \GF_Field $field The settings field instance.
 * @param string    $value Submitted event code.
 * @return void
 */
function validate_event_code_field( $field, $value ) {

	// Blank is fine here because forms not using the Salesforce source don't need one.
	if ( '' === trim( (string) $value ) ) {
		return;
	}

	// Otherwise, it has to match the format the query builder will accept.
	if ( ! QueryBuilders\is_valid_event_code( $value ) ) {
		$field->set_error( __( 'This doesn\'t look like a valid Salesforce event code.', 'salesforce-gravity-forms' ) );
	}
}
