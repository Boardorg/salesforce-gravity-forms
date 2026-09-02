<?php
/**
 * Reads the server-side Salesforce configuration.
 *
 * A defined PHP constant (typically set in wp-config.php) or an environment
 * variable of the same name always takes precedence, for environments that
 * support them (e.g. this plugin's local VIP Go development sandbox).
 *
 * Production runs on WP Engine's standard managed WordPress plans, which
 * offer neither environment variables nor deploy-time control over
 * wp-config.php. The only channels available there are SFTP and phpMyAdmin,
 * so — as an explicit, documented decision, not the handoff doc's original
 * "constants only" design — these values fall back to wp_options, entered
 * through the settings screen in includes/admin/credentials-settings.php.
 * That trades the doc's original defense (a compromised database alone
 * can't reveal the secret) for what SFTP/phpMyAdmin-only access actually
 * allows; see the README's "Configuration" section for the full tradeoff.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Default Salesforce REST API version, matching the existing app's default.
const DEFAULT_API_VERSION = '59.0';

// wp_options fallback keys, read only when neither a constant nor an
// environment variable of the corresponding name is defined.
const OPTION_LOGIN_URL     = 'sfgf_salesforce_login_url';
const OPTION_CLIENT_ID     = 'sfgf_salesforce_client_id';
const OPTION_CLIENT_SECRET = 'sfgf_salesforce_client_secret';
const OPTION_API_VERSION   = 'sfgf_salesforce_api_version';

/**
 * Reads a single configuration value, preferring a defined PHP constant
 * (typically set in wp-config.php), falling back to an environment
 * variable of the same name, and finally to a wp_options row for hosts that
 * offer neither.
 *
 * @param string $constant_name Constant/environment-variable name to read.
 * @param string $option_name   wp_options key to fall back to.
 * @return string|null The value, or null if none of the three sources define it.
 */
function read_value( $constant_name, $option_name ) {
	// A defined constant takes precedence over the environment.
	if ( defined( $constant_name ) ) {
		return (string) constant( $constant_name );
	}

	// Next, the environment (e.g. a VIP environment variable).
	$env_value = getenv( $constant_name );
	if ( false !== $env_value ) {
		return $env_value;
	}

	// Last resort: wp_options, for a host that provides neither of the above.
	$option_value = get_option( $option_name, '' );
	return '' === $option_value ? null : $option_value;
}

/**
 * Reads the Salesforce login/base URL.
 *
 * @return string|null
 */
function get_login_url() {
	return read_value( 'SFGF_SALESFORCE_LOGIN_URL', OPTION_LOGIN_URL );
}

/**
 * Reads the Salesforce Connected App / External Client App client ID.
 *
 * @return string|null
 */
function get_client_id() {
	return read_value( 'SFGF_SALESFORCE_CLIENT_ID', OPTION_CLIENT_ID );
}

/**
 * Reads the Salesforce Connected App / External Client App client secret.
 *
 * @return string|null
 */
function get_client_secret() {
	return read_value( 'SFGF_SALESFORCE_CLIENT_SECRET', OPTION_CLIENT_SECRET );
}

/**
 * Reads the configured Salesforce REST API version, defaulting to the same
 * version the existing Salesforce integration pins to.
 *
 * @return string
 */
function get_api_version() {
	$configured = read_value( 'SFGF_SALESFORCE_API_VERSION', OPTION_API_VERSION );
	// An explicit but empty value should still fall through to the default.
	return $configured ? $configured : DEFAULT_API_VERSION;
}

/**
 * Reads and validates the full credential bundle needed to talk to
 * Salesforce, failing loudly with a controlled error when anything required
 * is missing rather than letting a downstream OAuth call produce an opaque
 * failure.
 *
 * @return array|\WP_Error {
 *     @type string $login_url     Salesforce login/base URL.
 *     @type string $client_id     Connected App client ID.
 *     @type string $client_secret Connected App client secret.
 *     @type string $api_version   Salesforce REST API version.
 * }
 */
function get_credentials() {
	$login_url     = get_login_url();
	$client_id     = get_client_id();
	$client_secret = get_client_secret();

	// Collect every missing setting so the resulting error is actionable in
	// one read instead of requiring several failed attempts.
	$missing = [];
	if ( ! $login_url ) $missing[] = __( 'Salesforce Login URL', 'salesforce-gravity-forms' );
	if ( ! $client_id ) $missing[] = __( 'Client ID', 'salesforce-gravity-forms' );
	if ( ! $client_secret ) $missing[] = __( 'Client Secret', 'salesforce-gravity-forms' );

	if ( ! empty( $missing ) ) {
		return new \WP_Error(
			'sfgf_missing_config',
			sprintf(
				/* translators: %s: comma-separated list of missing setting labels. */
				__( 'Missing Salesforce configuration: %s. Set these under Settings → Salesforce, or as wp-config.php constants / environment variables.', 'salesforce-gravity-forms' ),
				implode( ', ', $missing )
			)
		);
	}

	return [
		'login_url'     => $login_url,
		'client_id'     => $client_id,
		'client_secret' => $client_secret,
		'api_version'   => get_api_version(),
	];
}
