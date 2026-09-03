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
add_action( 'gform_editor_js', __NAMESPACE__ . '\render_editor_js' );

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
	?>
	<li class="sfgf_choice_source_setting field_setting">
		<label for="sfgf_choice_source" class="section_label">
			<?php esc_html_e( 'Dynamic Choice Source', 'salesforce-gravity-forms' ); ?>
		</label>
		<select
			id="sfgf_choice_source"
			onchange="SetFieldProperty( '<?php echo esc_js( CHOICE_SOURCE_PROPERTY ); ?>', jQuery( this ).val() ); RefreshSelectedFieldPreview();"
		>
			<option value=""><?php esc_html_e( 'None', 'salesforce-gravity-forms' ); ?></option>
			<option value="<?php echo esc_attr( SPONSORS_SOURCE_VALUE ); ?>"><?php esc_html_e( 'Salesforce Sponsors', 'salesforce-gravity-forms' ); ?></option>
		</select>
		<p class="description">
			<?php esc_html_e( 'Populates this field\'s choices from Salesforce sponsor companies for the event code set in Form Settings, instead of the choices below.', 'salesforce-gravity-forms' ); ?>
		</p>
	</li>
	<?php
}

/**
 * Outputs the inline JS that shows this setting only for Checkbox fields
 * and keeps the dropdown in sync with whichever field is selected.
 *
 * @return void
 */
function render_editor_js() {
	?>
	<script>
		// Show this setting only for Checkbox fields.
		if ( fieldSettings.checkbox ) {
			fieldSettings.checkbox += ', .sfgf_choice_source_setting';
		}

		// Populate the dropdown whenever a field is selected.
		jQuery( document ).on( 'gform_load_field_settings', function ( event, field ) {
			jQuery( '#sfgf_choice_source' ).val( field[ '<?php echo esc_js( CHOICE_SOURCE_PROPERTY ); ?>' ] || '' );
		} );
	</script>
	<?php
}
