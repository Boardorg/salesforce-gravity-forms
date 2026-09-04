<?php
/**
 * Settings -> Salesforce admin page.
 *
 * @package SalesforceGravityForms
 *
 * @var string               $settings_group          Settings API group name.
 * @var string               $page_slug                Settings API page slug.
 * @var string               $test_connection_form_id  HTML id of the separate Test Connection form.
 * @var string               $test_connection_action    Nonce action for the Test Connection form.
 * @var string               $test_connection_nonce    Nonce field name for the Test Connection form.
 * @var string               $test_query_action        Nonce action for the Test Sponsor Query form.
 * @var string               $test_query_nonce         Nonce field name for the Test Sponsor Query form.
 * @var string               $event_code_value          Event code entered in the Test Sponsor Query form.
 * @var array|\WP_Error|null $query_test_result         Result of the Test Sponsor Query, or null if not run yet.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php
	// An otherwise-empty form for the Test Connection button below --
	// kept separate so a click never re-saves the settings above. Its
	// button lives inside the main form's markup via the HTML `form`
	// attribute, so the two buttons can sit on the same line.
	?>
	<form method="post" id="<?php echo esc_attr( $test_connection_form_id ); ?>">
		<?php wp_nonce_field( $test_connection_action, $test_connection_nonce ); ?>
		<input type="hidden" name="sfgf_test_connection" value="1">
	</form>

	<form action="options.php" method="post">
		<?php
		// Output security fields for the registered setting.
		settings_fields( $settings_group );

		// Output setting sections and their fields.
		do_settings_sections( $page_slug );
		?>
		<p class="submit">
			<?php submit_button( __( 'Save Settings', 'salesforce-gravity-forms' ), 'primary', 'submit', false ); ?>
			<button
				type="submit"
				form="<?php echo esc_attr( $test_connection_form_id ); ?>"
				class="button button-secondary"
			><?php esc_html_e( 'Test Connection', 'salesforce-gravity-forms' ); ?></button>
		</p>
	</form>

	<hr>

	<h2><?php esc_html_e( 'Test Sponsor Query', 'salesforce-gravity-forms' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Runs the actual sponsor query and normalization code against live Salesforce data for one event code -- nothing is cached or saved. Use a real, known Conference code from your Salesforce org.', 'salesforce-gravity-forms' ); ?>
	</p>
	<form method="post">
		<?php wp_nonce_field( $test_query_action, $test_query_nonce ); ?>
		<input
			type="text"
			name="sfgf_test_event_code"
			value="<?php echo esc_attr( $event_code_value ); ?>"
			class="regular-text"
			placeholder="e.g. NAMLS2026"
		>
		<input type="hidden" name="sfgf_test_query" value="1">
		<?php submit_button( __( 'Run Test Query', 'salesforce-gravity-forms' ), 'secondary' ); ?>
	</form>
	<?php \SalesforceGravityForms\Admin\CredentialsSettings\render_test_query_result( $query_test_result ); ?>
</div>
