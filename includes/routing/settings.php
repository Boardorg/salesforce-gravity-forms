<?php
/**
 * Readers for the Networking Profile router's settings.
 *
 * The values are saved on Settings → Networking Profile Router (see
 * includes/admin/router-settings.php). They are read here, in a file of their
 * own, so the router can use them on the front end without loading any admin
 * code.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\Routing\Settings;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// wp_options keys.
const OPTION_ENABLED             = 'sfgf_router_enabled';
const OPTION_PAGE_ID             = 'sfgf_router_page_id';
const OPTION_OVERRIDES           = 'sfgf_router_overrides';
const OPTION_TEMPLATE_FORM       = 'sfgf_router_template_form_id';
const OPTION_MESSAGE_NOT_OPEN    = 'sfgf_router_message_not_open';
const OPTION_MESSAGE_UNKNOWN     = 'sfgf_router_message_unknown_link';
const OPTION_MESSAGE_UNAVAILABLE = 'sfgf_router_message_unavailable';

// The three notices a visitor can be shown instead of a form.
const NOTICE_NOT_OPEN    = 'not_open';
const NOTICE_UNKNOWN     = 'unknown_link';
const NOTICE_UNAVAILABLE = 'unavailable';

/**
 * Whether the router is switched on. It ships switched off, so nothing changes
 * until someone has checked the forms table and turned it on.
 *
 * @return bool
 */
function is_enabled() {
	return '1' === (string) get_option( OPTION_ENABLED, '0' );
}

/**
 * The page the router watches: the one every Networking Profile link points at.
 *
 * @return int The page's ID, or 0 when none has been chosen.
 */
function get_router_page_id() {
	return absint( get_option( OPTION_PAGE_ID, 0 ) );
}

/**
 * The raw text of the overrides box.
 *
 * @return string
 */
function get_overrides_text() {
	return (string) get_option( OPTION_OVERRIDES, '' );
}

/**
 * The form a newly imported Networking Profile form copies its Salesforce feed from.
 *
 * @return int The form's ID, or 0 when none has been chosen.
 */
function get_template_form_id() {
	return absint( get_option( OPTION_TEMPLATE_FORM, 0 ) );
}

/**
 * The option that holds the text of one notice.
 *
 * @param string $type One of the NOTICE_* constants.
 * @return string The wp_options key, or '' for a type that doesn't exist.
 */
function get_message_option( $type ) {

	// Each notice type has its own option.
	$options = [
		NOTICE_NOT_OPEN    => OPTION_MESSAGE_NOT_OPEN,
		NOTICE_UNKNOWN     => OPTION_MESSAGE_UNKNOWN,
		NOTICE_UNAVAILABLE => OPTION_MESSAGE_UNAVAILABLE,
	];

	return $options[ $type ] ?? '';
}

/**
 * The wording a notice has until someone writes their own.
 *
 * @param string $type One of the NOTICE_* constants.
 * @return string
 */
function get_default_message( $type ) {

	// Plain, calm wording that doesn't promise anything we can't keep.
	$messages = [
		NOTICE_NOT_OPEN    => __( 'Your networking profile isn\'t open yet. We\'ll email you as soon as it is.', 'salesforce-gravity-forms' ),
		NOTICE_UNKNOWN     => __( 'We couldn\'t find a registration for this link. Please use the link in your most recent email, or contact us for help.', 'salesforce-gravity-forms' ),
		NOTICE_UNAVAILABLE => __( 'We\'re having a temporary problem loading your form. Please try again in a few minutes.', 'salesforce-gravity-forms' ),
	];

	return $messages[ $type ] ?? '';
}

/**
 * The wording to show for a notice: the saved text, or the default when none is saved.
 *
 * @param string $type One of the NOTICE_* constants.
 * @return string
 */
function get_message( $type ) {

	// Read what has been saved, if anything.
	$option = get_message_option( $type );
	$saved  = '' === $option ? '' : trim( (string) get_option( $option, '' ) );

	return '' === $saved ? get_default_message( $type ) : $saved;
}
