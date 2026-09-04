<?php
/**
 * Salesforce Login URL field.
 *
 * @package SalesforceGravityForms
 *
 * @var string $option_name wp_options key, and this field's name/id.
 * @var string $value       Current value.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<input
	type="url"
	name="<?php echo esc_attr( $option_name ); ?>"
	id="<?php echo esc_attr( $option_name ); ?>"
	value="<?php echo esc_attr( $value ); ?>"
	class="regular-text"
	placeholder="https://yourorg.my.salesforce.com"
>
<p class="description">
	Environment-specific — point a local/staging WordPress environment at a Salesforce sandbox login URL.
</p>
