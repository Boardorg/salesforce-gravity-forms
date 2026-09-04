<?php
/**
 * Per-field "Salesforce Sponsors" choice-source setting.
 *
 * Adds a "Dynamic Choice Source" dropdown to Checkbox fields in the form
 * editor. Setting it to "Salesforce Sponsors" is what tells dynamic-choices.php
 * to populate that field from ChoiceProvider\get_choices( 'sponsors',
 * $event_code ) instead of its own saved choices.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\GravityForms\FieldSettings;

// Set our aliases.
use SalesforceGravityForms as Core;
use SalesforceGravityForms\Helpers\Templates;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Define the field-meta property name this setting is stored under.
const CHOICE_SOURCE_PROPERTY = 'sfgfChoiceSource';

// Define the stored value that means "populate from Salesforce sponsors".
// Must match the source key registered in choice-provider.php.
const SPONSORS_SOURCE_VALUE = 'sponsors';

// Define the settings position this setting renders at: after "Enable Select All" options.
const SETTINGS_POSITION = 1363;

// Start our engines.
add_action( 'gform_field_standard_settings', __NAMESPACE__ . '\render_choice_source_setting', 10, 2 );
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_editor_assets' );

/**
 * Outputs the Dynamic Choice Source dropdown, at the one position we care about.
 *
 * @param int $position Settings position Gravity Forms is currently rendering.
 * @param int $form_id  Current form ID.
 * @return void
 */
function render_choice_source_setting( $position, $form_id ) {

	// Only render at the one position we're targeting.
	if ( SETTINGS_POSITION !== $position ) {
		return;
	}

	Templates\render(
		'gravity-forms/choice-source-setting.php',
		[
			'property_name'  => CHOICE_SOURCE_PROPERTY,
			'sponsors_value' => SPONSORS_SOURCE_VALUE,
		]
	);
}

/**
 * Enqueues the JS that shows the choice-source setting only for Checkbox
 * fields and keeps its dropdown in sync with whichever field is selected --
 * only on the form editor screen, where that setting actually appears.
 *
 * @return void
 */
function enqueue_editor_assets() {

	// Only the form editor screen has the field settings this JS targets.
	if ( ! class_exists( 'GFForms' ) || 'form_editor' !== \GFForms::get_page() ) {
		return;
	}

	wp_enqueue_script(
		'sfgf-field-settings-editor',
		Core\URL . 'assets/js/field-settings-editor.js',
		[ 'jquery' ],
		Core\VERS,
		true
	);

	// Pass the field-meta property name the script reads and writes.
	wp_localize_script(
		'sfgf-field-settings-editor',
		'sfgfFieldSettings',
		[ 'choiceSourceProperty' => CHOICE_SOURCE_PROPERTY ]
	);
}
