<?php
/**
 * The single place Gravity Forms asks for a normalized list of choices. 
 * Also handles caching: reuse a fresh list if we have one, and fall
 * back to a stale list if a live lookup fails.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\Providers\ChoiceProvider;

// Set our aliases.
use SalesforceGravityForms\Providers\SalesforceSponsorProvider;
use SalesforceGravityForms\Cache\ChoiceCache;
use SalesforceGravityForms\Helpers\Utilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Returns the choice list for one source and event. Uses a fresh cached
 * list if we have one; otherwise queries live and caches the result. If the
 * live query fails, falls back to a stale cached list, or returns an error
 * if there's no stale list either.
 *
 * @param string $source     Which source to use (e.g. 'sponsors').
 * @param string $event_code Conference/event code the choices are scoped to.
 * @return array|\WP_Error The choices, each shaped like:
 *     { value, label, source_id, active, metadata: { attendee_ids } }
 */
function get_choices( $source, $event_code ) {

	// Return the cached list if it's still fresh.
	$fresh = ChoiceCache\get_fresh( $source, $event_code );
	if ( null !== $fresh ) {
		return $fresh;
	}

	// Look up which function handles this source.
	$providers = apply_filters( 'sfgf_choice_providers', [ 'sponsors' => __NAMESPACE__ . '\\dispatch_sponsors' ] );

	// Bail if no provider is registered for this source.
	if ( ! isset( $providers[ $source ] ) || ! is_callable( $providers[ $source ] ) ) {
		return new \WP_Error(
			'sfgf_unknown_choice_source',
			sprintf(
				/* translators: %s: the unrecognized choice-source key. */
				__( 'No choice provider is registered for source "%s".', 'salesforce-gravity-forms' ),
				$source
			)
		);
	}

	// Ask the provider for its choices.
	$choices = call_user_func( $providers[ $source ], $event_code );

	// Did the provider return an error?
	if ( is_wp_error( $choices ) ) {

		// Log the error.
		Utilities\log(
			'error',
			'Choice provider failed; attempting stale-cache fallback',
			[ 'source' => $source, 'event_code' => $event_code, 'error' => $choices->get_error_code() ]
		);

		// Fall back to the stale list, if there is one.
		$stale = ChoiceCache\get_stale( $source, $event_code );
		if ( null !== $stale ) {
			return apply_filters( 'sfgf_choices', $stale, $source, $event_code );
		}

		// No stale list exists so return the error.
		return $choices;
	}

	// Cache the result, both as the fresh copy and as the stale fallback.
	ChoiceCache\set_fresh( $source, $event_code, $choices );
	ChoiceCache\set_stale( $source, $event_code, $choices );

	// Return the choices with a filter hook.
	return apply_filters( 'sfgf_choices', $choices, $source, $event_code );
}

/**
 * Calls the built-in Salesforce sponsor provider. Kept as its own function
 * so the provider list above stays easy to read.
 *
 * @param string $event_code Conference/event code.
 * @return array|\WP_Error
 */
function dispatch_sponsors( $event_code ) {
	return SalesforceSponsorProvider\get_choices( $event_code );
}
