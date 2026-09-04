<?php
/**
 * "Dynamic Choice Source" dropdown in the form editor's field settings.
 *
 * @package SalesforceGravityForms
 *
 * @var string $property_name  Field-meta property name this setting is stored under.
 * @var string $sponsors_value Stored value that means "populate from Salesforce sponsors".
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<li class="sfgf_choice_source_setting field_setting">
	<label for="sfgf_choice_source" class="section_label">
		<?php esc_html_e( 'Dynamic Choice Source', 'salesforce-gravity-forms' ); ?>
	</label>
	<select
		id="sfgf_choice_source"
		onchange="SetFieldProperty( '<?php echo esc_js( $property_name ); ?>', jQuery( this ).val() ); RefreshSelectedFieldPreview();"
	>
		<option value=""><?php esc_html_e( 'None', 'salesforce-gravity-forms' ); ?></option>
		<option value="<?php echo esc_attr( $sponsors_value ); ?>"><?php esc_html_e( 'Salesforce Sponsors', 'salesforce-gravity-forms' ); ?></option>
	</select>
	<p class="description">
		<?php esc_html_e( 'Populates this field\'s choices from Salesforce sponsor companies for the event code set in Form Settings, instead of the choices below.', 'salesforce-gravity-forms' ); ?>
	</p>
</li>
