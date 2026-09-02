<?php
/**
 * Normalized choice contract consumed by Gravity Forms.
 *
 * This is the seam between "where the data came from" and "how a Gravity
 * Forms field renders it". The Gravity Forms layer (a later phase) will only
 * ever call get_choices() here and will never know Salesforce is involved —
 * a future proxy/API-backed provider can be registered under the same
 * source key without touching any Gravity Forms integration code.
 *
 * Also owns the fresh/stale caching policy described in the handoff doc's
 * "Choice caching and failure behavior" section, since that policy applies
 * to any provider, not just the Salesforce one.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\Providers\ChoiceProvider;

use SalesforceGravityForms\Providers\SalesforceSponsorProvider;
use SalesforceGravityForms\Cache\ChoiceCache;
use SalesforceGravityForms\Helpers\Utilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Returns the normalized choice list for one source and event, applying the
 * fresh/stale cache policy: a fresh-cache hit is returned immediately; a
 * fresh-cache miss queries the provider; a provider failure falls back to
 * the stale cache; and only a provider failure with no stale cache at all
 * produces an error, so a Gravity Forms field can distinguish "empty list"
 * from "we could not safely determine the list."
 *
 * @param string $source     Registered provider source key (e.g. 'sponsors').
 * @param string $event_code Conference/event code the choices are scoped to.
 * @return array|\WP_Error Normalized choices, each shaped like:
 *     { value, label, source_id, active, metadata: { attendee_ids } }
 */
function get_choices( $source, $event_code ) {
	// Fast path: a still-fresh cached list needs no provider call at all.
	$fresh = ChoiceCache\get_fresh( $source, $event_code );
	if ( null !== $fresh ) {
		return $fresh;
	}

	$providers = apply_filters( 'sfgf_choice_providers', [ 'sponsors' => __NAMESPACE__ . '\\dispatch_sponsors' ] );

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

	$choices = call_user_func( $providers[ $source ], $event_code );

	if ( is_wp_error( $choices ) ) {
		Utilities\log(
			'error',
			'Choice provider failed; attempting stale-cache fallback',
			[ 'source' => $source, 'event_code' => $event_code, 'error' => $choices->get_error_code() ]
		);

		$stale = ChoiceCache\get_stale( $source, $event_code );
		if ( null !== $stale ) {
			return apply_filters( 'sfgf_choices', $stale, $source, $event_code );
		}

		// No stale list to fall back to — surface the controlled error
		// rather than silently accepting arbitrary submitted values.
		return $choices;
	}

	ChoiceCache\set_fresh( $source, $event_code, $choices );
	// Only ever updated after a successful query, per the handoff doc.
	ChoiceCache\set_stale( $source, $event_code, $choices );

	return apply_filters( 'sfgf_choices', $choices, $source, $event_code );
}

/**
 * Thin adapter to the built-in Salesforce sponsor provider, kept as its own
 * function (rather than referencing SalesforceSponsorProvider\get_choices
 * directly in the array literal above) so the default array is easy to read
 * without an extra `use function` import line.
 *
 * @param string $event_code Conference/event code.
 * @return array|\WP_Error
 */
function dispatch_sponsors( $event_code ) {
	return SalesforceSponsorProvider\get_choices( $event_code );
}
