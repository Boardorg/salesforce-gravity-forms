<?php
/**
 * Per-form Salesforce event-code setting.
 *
 * Adds a "Salesforce Event Code" field to the form editor's Form Settings
 * screen. Any Checkbox field on the form can opt into the Salesforce
 * sponsor source (see field-settings.php); they all share this one
 * form-level event code.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\GravityForms\FormSettings;

// Set our aliases.
use SalesforceGravityForms\Salesforce\QueryBuilders;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Define the form-meta property name this setting is stored under.
const EVENT_CODE_PROPERTY = 'sfgfEventCode';

// Start our engines.
add_filter( 'gform_form_settings_fields', __NAMESPACE__ . '\add_event_code_field' );

/**
 * Adds the Salesforce Event Code field to a new settings section.
 *
 * @param array $fields Existing form settings sections/fields.
 * @return array The filtered sections/fields.
 */
function add_event_code_field( $fields ) {

	// Add a new section so this setting doesn't get lost among GF's own settings.
	$fields['salesforce'] = [
		'title'  => __( 'Salesforce Integration', 'salesforce-gravity-forms' ),
		'fields' => [
			[
				'name'                => EVENT_CODE_PROPERTY,
				'type'                => 'text',
				'label'               => __( 'Salesforce Event Code', 'salesforce-gravity-forms' ),
				'tooltip'             => __( 'Conference code used to look up this form\'s eligible sponsor companies. An administrator-set value only -- never taken from the page or a hidden field.', 'salesforce-gravity-forms' ),
				'validation_callback' => __NAMESPACE__ . '\validate_event_code_field',
			],
		],
	];

	return $fields;
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
