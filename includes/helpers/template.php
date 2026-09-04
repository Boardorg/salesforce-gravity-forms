<?php
/**
 * Loads a template file from the templates/ directory.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\Helpers\Templates;

// Set our aliases.
use SalesforceGravityForms as Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Includes one template file, making each key of $vars available to it as a
 * variable of the same name.
 *
 * @param string $relative_path Path under templates/, e.g. 'admin/settings-page.php'.
 * @param array  $vars          Variables the template needs.
 * @return void
 */
function render( $relative_path, $vars = [] ) {

	// Get the full path to the template file.
	$path = Core\TEMPLATES_PATH . '/' . ltrim( $relative_path, '/' );

	// Bail if the template doesn't exist.
	if ( ! file_exists( $path ) ) {
		return;
	}

	// Pull each variable into its own name so the template reads like plain HTML.
	extract( $vars ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Deliberate: every $key => $value here is one we defined below, not user input.

	include $path;
}
