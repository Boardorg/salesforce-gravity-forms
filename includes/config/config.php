<?php
/**
 * Reads the server-side Salesforce configuration.
 *
 * A defined constant or environment variable always takes precedence. Falls
 * back to wp_options, entered via includes/admin/credentials-settings.php,
 * for hosts like production's WP Engine plan that offer neither -- see the
 * README's "Configuration" section for the tradeoff.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Default Salesforce REST API version.
const DEFAULT_API_VERSION = '59.0';

// Define wp_options fallback keys.
const OPTION_LOGIN_URL     = 'sfgf_salesforce_login_url';
const OPTION_CLIENT_ID     = 'sfgf_salesforce_client_id';
const OPTION_CLIENT_SECRET = 'sfgf_salesforce_client_secret';
const OPTION_API_VERSION   = 'sfgf_salesforce_api_version';

// wp_options key for the admin-configurable "sponsor field unavailable" message.
const OPTION_UNAVAILABLE_MESSAGE = 'sfgf_unavailable_message_text';

/**
 * Reads a single configuration value: a defined constant first, then an
 * environment variable, then wp_options.
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

	// Next, check the environment.
	$env_value = getenv( $constant_name );
	if ( false !== $env_value ) {
		return $env_value;
	}

	// Last resort: check wp_options table.
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
 * Reads the configured Salesforce REST API version, or the default if unset.
 *
 * @return string
 */
function get_api_version() {

	// Read the configured version, if any.
	$configured = read_value( 'SFGF_SALESFORCE_API_VERSION', OPTION_API_VERSION );

	// An empty value should still fall through to the default.
	return $configured ? $configured : DEFAULT_API_VERSION;
}

/**
 * Reads the admin-configured "sponsor field unavailable" message, or '' if
 * one hasn't been set.
 *
 * @return string
 */
function get_unavailable_message_override() {
	return (string) get_option( OPTION_UNAVAILABLE_MESSAGE, '' );
}

/**
 * Reads and validates the full credential bundle, returns an
 * error if anything required is missing.
 *
 * @return array|\WP_Error {
 *     @type string $login_url     Salesforce login/base URL.
 *     @type string $client_id     Connected App client ID.
 *     @type string $client_secret Connected App client secret.
 *     @type string $api_version   Salesforce REST API version.
 * }
 */
function get_credentials() {

	// Read each configured credential.
	$login_url     = get_login_url();
	$client_id     = get_client_id();
	$client_secret = get_client_secret();

	// Collect every missing setting.
	$missing = [];
	if ( ! $login_url ) $missing[] = __( 'Salesforce Login URL', 'salesforce-gravity-forms' );
	if ( ! $client_id ) $missing[] = __( 'Client ID', 'salesforce-gravity-forms' );
	if ( ! $client_secret ) $missing[] = __( 'Client Secret', 'salesforce-gravity-forms' );

	// If anything is missing, return an error.
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

	// Otherwise, return the full credential bundle.
	return [
		'login_url'     => $login_url,
		'client_id'     => $client_id,
		'client_secret' => $client_secret,
		'api_version'   => get_api_version(),
	];
}
