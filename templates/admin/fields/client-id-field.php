<?php
/**
 * Client ID field.
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
	type="text"
	name="<?php echo esc_attr( $option_name ); ?>"
	id="<?php echo esc_attr( $option_name ); ?>"
	value="<?php echo esc_attr( $value ); ?>"
	class="regular-text"
>
