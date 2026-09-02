<?php
/**
 * Reads the server-side Salesforce configuration.
 *
 * Credentials are intentionally never stored in wp_options, Gravity Forms
 * metadata, or an admin-editable form field. They must be defined as
 * environment variables or wp-config.php constants, matching the handoff
 * doc's "Configuration design" section.
 *
 * @package BoardMCSalesforceGravityForms
 */

// Declare our namespace.
namespace BoardMC\SalesforceGravityForms\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Default Salesforce REST API version, matching the existing app's default.
const DEFAULT_API_VERSION = '59.0';

/**
 * Reads a single configuration value, preferring a defined PHP constant
 * (typically set in wp-config.php) and falling back to an environment
 * variable of the same name.
 *
 * @param string $name Constant/environment-variable name to read.
 * @return string|null The value, or null if neither source defines it.
 */
function read_value( $name ) {
	// A defined constant takes precedence over the environment.
	if ( defined( $name ) ) {
		return (string) constant( $name );
	}

	// Fall back to the environment (e.g. a VIP environment variable).
	$env_value = getenv( $name );
	return false === $env_value ? null : $env_value;
}

/**
 * Reads the Salesforce login/base URL.
 *
 * @return string|null
 */
function get_login_url() {
	return read_value( 'BOARDMC_SFGF_SALESFORCE_LOGIN_URL' );
}

/**
 * Reads the Salesforce Connected App / External Client App client ID.
 *
 * @return string|null
 */
function get_client_id() {
	return read_value( 'BOARDMC_SFGF_SALESFORCE_CLIENT_ID' );
}

/**
 * Reads the Salesforce Connected App / External Client App client secret.
 *
 * @return string|null
 */
function get_client_secret() {
	return read_value( 'BOARDMC_SFGF_SALESFORCE_CLIENT_SECRET' );
}

/**
 * Reads the configured Salesforce REST API version, defaulting to the same
 * version the existing Salesforce integration pins to.
 *
 * @return string
 */
function get_api_version() {
	$configured = read_value( 'BOARDMC_SFGF_SALESFORCE_API_VERSION' );
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
	if ( ! $login_url ) $missing[] = 'BOARDMC_SFGF_SALESFORCE_LOGIN_URL';
	if ( ! $client_id ) $missing[] = 'BOARDMC_SFGF_SALESFORCE_CLIENT_ID';
	if ( ! $client_secret ) $missing[] = 'BOARDMC_SFGF_SALESFORCE_CLIENT_SECRET';

	if ( ! empty( $missing ) ) {
		return new \WP_Error(
			'boardmc_sfgf_missing_config',
			sprintf(
				/* translators: %s: comma-separated list of missing constant names. */
				__( 'Missing Salesforce configuration: %s', 'boardmc-salesforce-gravity-forms' ),
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
