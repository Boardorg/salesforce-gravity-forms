<?php
/**
 * Client Secret field. Write-only: the saved value is never echoed back
 * into the page, only whether one is currently set.
 *
 * @package SalesforceGravityForms
 *
 * @var string $option_name wp_options key, and this field's name/id.
 * @var bool   $has_secret  Whether a secret is currently saved.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<input
	type="password"
	name="<?php echo esc_attr( $option_name ); ?>"
	id="<?php echo esc_attr( $option_name ); ?>"
	value=""
	class="regular-text"
	autocomplete="new-password"
>
<p class="description">
	<?php
	echo $has_secret
		? esc_html__( 'A client secret is already saved. Leave blank to keep it unchanged.', 'salesforce-gravity-forms' )
		: esc_html__( 'No client secret saved yet.', 'salesforce-gravity-forms' );
	?>
</p>
