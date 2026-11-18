<?php
/**
 * The short notice a visitor sees instead of a form: no form for their event yet,
 * a link that isn't recognised, or Salesforce being out of reach. It is printed
 * between the theme's own header and footer.
 *
 * @package SalesforceGravityForms
 *
 * @var string $title        The heading.
 * @var string $message_html The wording, already cleaned with wp_kses_post().
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<main class="sfgf-router-notice" style="max-width: 40rem; margin: 4rem auto; padding: 0 1.25rem;">
	<h1><?php echo esc_html( $title ); ?></h1>
	<?php echo $message_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Cleaned with wp_kses_post() before it gets here. ?>
</main>
