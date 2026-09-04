<?php
/**
 * Sponsor-field-unavailable message field.
 *
 * @package SalesforceGravityForms
 *
 * @var string $option_name wp_options key, and this field's name/id.
 * @var string $value       Current value.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<textarea
	name="<?php echo esc_attr( $option_name ); ?>"
	id="<?php echo esc_attr( $option_name ); ?>"
	class="large-text"
	rows="2"
	placeholder="<?php esc_attr_e( 'Sponsor list is temporarily unavailable. Please try again shortly.', 'salesforce-gravity-forms' ); ?>"
><?php echo esc_textarea( $value ); ?></textarea>
<p class="description">
	<?php esc_html_e( 'Shown in place of the sponsor checkboxes, and as the validation error if the field is required. Leave blank to use the default message above.', 'salesforce-gravity-forms' ); ?>
</p>
