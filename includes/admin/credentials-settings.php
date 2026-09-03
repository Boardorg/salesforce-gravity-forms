<?php
/**
 * Admin settings screen for the Salesforce credentials.
 *
 * Exists specifically for the WP Engine standard managed WordPress plan
 * this plugin runs on in production, where SFTP and phpMyAdmin are the only
 * available channels — see includes/config/config.php's file header and the
 * README's "Configuration" section for why wp_options is used here.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\Admin\CredentialsSettings;

// Set our aliases.
use SalesforceGravityForms\Config;
use SalesforceGravityForms\Salesforce\Authentication;
use SalesforceGravityForms\Salesforce\QueryBuilders;
use SalesforceGravityForms\Salesforce\SponsorRecords;
use SalesforceGravityForms\Providers\SalesforceSponsorProvider;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Settings API group and page slug shared by every field registered below.
const SETTINGS_GROUP = 'sfgf_credentials';
const PAGE_SLUG      = 'sfgf-credentials';

// Nonce action/name for the "Test Connection" button, kept separate from
// the Settings API's own nonce (settings_fields()) since it posts back to
// this page directly instead of to options.php.
const TEST_CONNECTION_ACTION = 'sfgf_test_connection';
const TEST_CONNECTION_NONCE  = 'sfgf_test_connection_nonce';

// Nonce action/name for the "Test Sponsor Query" tool, same reasoning.
const TEST_QUERY_ACTION = 'sfgf_test_query';
const TEST_QUERY_NONCE  = 'sfgf_test_query_nonce';

// Start our engines.
add_action( 'admin_menu', __NAMESPACE__ . '\add_settings_page' );
add_action( 'admin_init', __NAMESPACE__ . '\register_settings' );

/**
 * Add the credentials settings page to the admin menu, under Settings.
 *
 * @return void
 */
function add_settings_page() {

	// Add submenu page under Settings.
	add_submenu_page(
		'options-general.php',
		__( 'Salesforce Gravity Forms', 'salesforce-gravity-forms' ),
		__( 'Salesforce', 'salesforce-gravity-forms' ),
		'manage_options',
		PAGE_SLUG,
		__NAMESPACE__ . '\render_settings_page'
	);
}

/**
 * Register settings, sections, and fields for the Salesforce credentials.
 *
 * @return void
 */
function register_settings() {

	// Create each option with autoload disabled if it doesn't exist yet.
	add_option( Config\OPTION_LOGIN_URL, '', '', false );
	add_option( Config\OPTION_CLIENT_ID, '', '', false );
	add_option( Config\OPTION_CLIENT_SECRET, '', '', false );
	add_option( Config\OPTION_API_VERSION, Config\DEFAULT_API_VERSION, '', false );

	// Register setting for the Salesforce login/base URL.
	register_setting(
		SETTINGS_GROUP,
		Config\OPTION_LOGIN_URL,
		array(
			'type'              => 'string',
			'description'       => 'Salesforce login/base URL',
			'sanitize_callback' => __NAMESPACE__ . '\sanitize_login_url',
			'default'           => '',
		)
	);

	// Register setting for the Connected App / External Client App client ID.
	register_setting(
		SETTINGS_GROUP,
		Config\OPTION_CLIENT_ID,
		array(
			'type'              => 'string',
			'description'       => 'Salesforce Connected App / External Client App client ID',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		)
	);

	// Register setting for the client secret. A blank submission is treated
	// as "leave unchanged" by sanitize_client_secret() -- see its docblock.
	register_setting(
		SETTINGS_GROUP,
		Config\OPTION_CLIENT_SECRET,
		array(
			'type'              => 'string',
			'description'       => 'Salesforce Connected App / External Client App client secret',
			'sanitize_callback' => __NAMESPACE__ . '\sanitize_client_secret',
			'default'           => '',
		)
	);

	// Register setting for the Salesforce REST API version.
	register_setting(
		SETTINGS_GROUP,
		Config\OPTION_API_VERSION,
		array(
			'type'              => 'string',
			'description'       => 'Salesforce REST API version',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => Config\DEFAULT_API_VERSION,
		)
	);

	// Add the settings section.
	add_settings_section(
		'sfgf_credentials_main',
		__( 'Salesforce Connection', 'salesforce-gravity-forms' ),
		__NAMESPACE__ . '\render_main_section_description',
		PAGE_SLUG
	);

	// Add each field to that section.
	add_settings_field( Config\OPTION_LOGIN_URL, __( 'Salesforce Login URL', 'salesforce-gravity-forms' ), __NAMESPACE__ . '\render_login_url_field', PAGE_SLUG, 'sfgf_credentials_main' );
	add_settings_field( Config\OPTION_CLIENT_ID, __( 'Client ID', 'salesforce-gravity-forms' ), __NAMESPACE__ . '\render_client_id_field', PAGE_SLUG, 'sfgf_credentials_main' );
	add_settings_field( Config\OPTION_CLIENT_SECRET, __( 'Client Secret', 'salesforce-gravity-forms' ), __NAMESPACE__ . '\render_client_secret_field', PAGE_SLUG, 'sfgf_credentials_main' );
	add_settings_field( Config\OPTION_API_VERSION, __( 'API Version', 'salesforce-gravity-forms' ), __NAMESPACE__ . '\render_api_version_field', PAGE_SLUG, 'sfgf_credentials_main' );
}

/**
 * Render the settings page.
 *
 * @return void
 */
function render_settings_page() {

	// Check user capabilities.
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Handle a "Test Connection" submission before rendering.
	if ( isset( $_POST['sfgf_test_connection'] ) && check_admin_referer( TEST_CONNECTION_ACTION, TEST_CONNECTION_NONCE ) ) {
		handle_test_connection();
	}

	// Handle a "Test Sponsor Query" submission.
	$event_code_value = isset( $_POST['sfgf_test_event_code'] ) ? sanitize_text_field( wp_unslash( $_POST['sfgf_test_event_code'] ) ) : '';
	$query_test_result = null;
	if ( isset( $_POST['sfgf_test_query'] ) && check_admin_referer( TEST_QUERY_ACTION, TEST_QUERY_NONCE ) ) {
		$query_test_result = run_test_query( $event_code_value );
	}

	// Add error/update messages.
	settings_errors( SETTINGS_GROUP );
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

		<form action="options.php" method="post">
			<?php
			// Output security fields for the registered setting.
			settings_fields( SETTINGS_GROUP );

			// Output setting sections and their fields.
			do_settings_sections( PAGE_SLUG );

			// Output save settings button.
			submit_button( __( 'Save Credentials', 'salesforce-gravity-forms' ) );
			?>
		</form>

		<form method="post">
			<?php
			// Separate nonce/form so this never accidentally re-saves the
			// credentials fields above -- it only exercises authenticate().
			wp_nonce_field( TEST_CONNECTION_ACTION, TEST_CONNECTION_NONCE );
			?>
			<input type="hidden" name="sfgf_test_connection" value="1">
			<?php submit_button( __( 'Test Connection', 'salesforce-gravity-forms' ), 'secondary' ); ?>
		</form>

		<hr>

		<h2><?php esc_html_e( 'Test Sponsor Query', 'salesforce-gravity-forms' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Runs the actual sponsor query and normalization code against live Salesforce data for one event code -- nothing is cached or saved. Use a real, known Conference code from your Salesforce org.', 'salesforce-gravity-forms' ); ?>
		</p>
		<form method="post">
			<?php wp_nonce_field( TEST_QUERY_ACTION, TEST_QUERY_NONCE ); ?>
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
		<?php render_test_query_result( $query_test_result ); ?>
	</div>
	<?php
}

/**
 * Runs the sponsor query-builder, client, and normalization code for one
 * event code and returns a summary suitable for on-screen display. Always
 * live -- deliberately bypasses ChoiceCache so every click reflects current
 * Salesforce data.
 *
 * @param string $event_code Event code to test.
 * @return array{soql: string, raw_count: int, skipped_count: int, choices: array}|\WP_Error
 */
function run_test_query( $event_code ) {

	// Validate the event code is non-empty.
	if ( '' === trim( $event_code ) ) {
		return new \WP_Error( 'sfgf_test_query_missing_event_code', __( 'Enter an event code to test.', 'salesforce-gravity-forms' ) );
	}

	// Build (and validate) the SOQL first.
	$soql = QueryBuilders\build_sponsor_query( $event_code );
	if ( is_wp_error( $soql ) ) {
		return $soql;
	}

	// Live query -- SponsorRecords/Client/Authentication handle pagination,
	// auth, and the invalid-session retry.
	$records = SponsorRecords\get_raw_sponsor_records( $event_code );
	if ( is_wp_error( $records ) ) {
		return $records;
	}

	// Normalize the raw records into distinct companies by Account Id.
	list( $companies, $skipped_count ) = SalesforceSponsorProvider\group_by_account( $records );

	// Return a summary for on-screen display.
	return [
		'soql'          => $soql,
		'raw_count'     => count( $records ),
		'skipped_count' => $skipped_count,
		'choices'       => SalesforceSponsorProvider\sort_choices( array_values( $companies ) ),
	];
}

/**
 * Renders the result of run_test_query(): a summary line, a table of the
 * normalized choices (label, Account Id, attendee-row count), and the SOQL
 * used, collapsed behind a <details> element.
 *
 * @param array|\WP_Error|null $result Return value of run_test_query(), or null if no test has run yet.
 * @return void
 */
function render_test_query_result( $result ) {

	// If no test has run yet, don't render anything.
	if ( null === $result ) {
		return;
	}
	?>
	<div style="margin-top: 1em; max-width: 900px;">
		<?php if ( is_wp_error( $result ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $result->get_error_message() ); ?></p></div>
			<?php return; ?>
		<?php endif; ?>

		<div class="notice notice-success inline">
			<p>
				<?php
				printf(
					/* translators: 1: raw qualifying row count, 2: distinct sponsor company count after deduplication. */
					esc_html__( '%1$d qualifying Attendee__c row(s) returned, %2$d distinct sponsor compan(y/ies) after deduplication by Account Id.', 'salesforce-gravity-forms' ),
					(int) $result['raw_count'],
					count( $result['choices'] )
				);
				?>
				<?php if ( $result['skipped_count'] > 0 ) : ?>
					<?php
					printf(
						/* translators: %d: count of rows skipped for missing Account Id/Name. */
						esc_html__( ' %d row(s) skipped for missing Account Id or Account Name.', 'salesforce-gravity-forms' ),
						(int) $result['skipped_count']
					);
					?>
				<?php endif; ?>
			</p>
		</div>

		<?php if ( ! empty( $result['choices'] ) ) : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Label (Account Name)', 'salesforce-gravity-forms' ); ?></th>
						<th><?php esc_html_e( 'Value (Account Id)', 'salesforce-gravity-forms' ); ?></th>
						<th><?php esc_html_e( 'Attendee Rows', 'salesforce-gravity-forms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $result['choices'] as $choice ) : ?>
						<tr>
							<td><?php echo esc_html( $choice['label'] ); ?></td>
							<td><code><?php echo esc_html( $choice['value'] ); ?></code></td>
							<td><?php echo (int) count( $choice['metadata']['attendee_ids'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<details style="margin-top: 1em;">
			<summary><?php esc_html_e( 'SOQL used', 'salesforce-gravity-forms' ); ?></summary>
			<pre style="white-space: pre-wrap; background: #f6f7f7; padding: 1em;"><?php echo esc_html( $result['soql'] ); ?></pre>
		</details>
	</div>
	<?php
}

/**
 * Attempts a Salesforce authentication and records the result as a
 * settings-error notice for settings_errors() to display.
 *
 * @return void
 */
function handle_test_connection() {

	// Attempt to authenticate with the current credentials.
	$result = Authentication\authenticate();

	// If it failed, add an error notice with the sanitized error message.
	if ( is_wp_error( $result ) ) {
		add_settings_error(
			SETTINGS_GROUP,
			'sfgf_test_connection',
			sprintf(
				/* translators: %s: sanitized error message, never contains the client secret. */
				__( 'Connection failed: %s', 'salesforce-gravity-forms' ),
				$result->get_error_message()
			),
			'error'
		);
		return;
	}

	// If it succeeded, add a success notice with the instance URL.
	add_settings_error(
		SETTINGS_GROUP,
		'sfgf_test_connection',
		sprintf(
			/* translators: %s: the Salesforce instance URL that authenticated successfully. */
			__( 'Connected successfully. Instance URL: %s', 'salesforce-gravity-forms' ),
			$result['instance_url']
		),
		'success'
	);
}

/**
 * Render section description.
 *
 * @return void
 */
function render_main_section_description() {
	echo '<p>' . esc_html__( 'Credentials for a dedicated, least-privilege Salesforce integration user. Do not reuse a human user’s login or another Salesforce-connected app’s credentials.', 'salesforce-gravity-forms' ) . '</p>';
	echo '<p>' . esc_html__( 'Stored in wp_options. If a matching wp-config.php constant or environment variable is defined instead, it always takes precedence and these fields are ignored.', 'salesforce-gravity-forms' ) . '</p>';
}

/**
 * Render Salesforce login URL field.
 *
 * @return void
 */
function render_login_url_field() {

	// Get current value.
	$value = get_option( Config\OPTION_LOGIN_URL, '' );
	?>
	<input
		type="url"
		name="<?php echo esc_attr( Config\OPTION_LOGIN_URL ); ?>"
		id="<?php echo esc_attr( Config\OPTION_LOGIN_URL ); ?>"
		value="<?php echo esc_attr( $value ); ?>"
		class="regular-text"
		placeholder="https://yourorg.my.salesforce.com"
	>
	<p class="description">
		Environment-specific — point a local/staging WordPress environment at a Salesforce sandbox login URL.
	</p>
	<?php
}

/**
 * Render Client ID field.
 *
 * @return void
 */
function render_client_id_field() {

	// Get current value.
	$value = get_option( Config\OPTION_CLIENT_ID, '' );
	?>
	<input
		type="text"
		name="<?php echo esc_attr( Config\OPTION_CLIENT_ID ); ?>"
		id="<?php echo esc_attr( Config\OPTION_CLIENT_ID ); ?>"
		value="<?php echo esc_attr( $value ); ?>"
		class="regular-text"
	>
	<?php
}

/**
 * Render Client Secret field. Write-only: the saved value is never echoed
 * back into the page, only whether one is currently set.
 *
 * @return void
 */
function render_client_secret_field() {

	// Only check whether a secret is saved -- never read its value into the page.
	$has_secret = '' !== get_option( Config\OPTION_CLIENT_SECRET, '' );
	?>
	<input
		type="password"
		name="<?php echo esc_attr( Config\OPTION_CLIENT_SECRET ); ?>"
		id="<?php echo esc_attr( Config\OPTION_CLIENT_SECRET ); ?>"
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
	<?php
}

/**
 * Render API Version field.
 *
 * @return void
 */
function render_api_version_field() {

	// Get current value.
	$value = get_option( Config\OPTION_API_VERSION, Config\DEFAULT_API_VERSION );
	?>
	<input
		type="text"
		name="<?php echo esc_attr( Config\OPTION_API_VERSION ); ?>"
		id="<?php echo esc_attr( Config\OPTION_API_VERSION ); ?>"
		value="<?php echo esc_attr( $value ); ?>"
		class="regular-text"
		placeholder="<?php echo esc_attr( Config\DEFAULT_API_VERSION ); ?>"
	>
	<?php
}

/**
 * Sanitize the Salesforce login URL.
 *
 * @param string $input Raw submitted value.
 * @return string
 */
function sanitize_login_url( $input ) {
	return esc_url_raw( trim( (string) $input ) );
}

/**
 * Sanitize the Client Secret submission. Because the field is write-only
 * (see render_client_secret_field()), a blank submission means "leave the
 * stored secret unchanged" rather than "clear it" -- otherwise an admin
 * would have to re-enter the secret every time they only meant to update
 * the login URL or client ID.
 *
 * @param string $input Raw submitted value.
 * @return string
 */
function sanitize_client_secret( $input ) {

	// Trim and cast to string first.
	$input = trim( (string) $input );

	// Blank means "unchanged" so return the existing stored value as-is.
	if ( '' === $input ) {
		return get_option( Config\OPTION_CLIENT_SECRET, '' );
	}

	// Otherwise, sanitize and return the new value.
	return sanitize_text_field( $input );
}
