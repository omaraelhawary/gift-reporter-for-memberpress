<?php
/**
 * Reminder Email Body Template
 * 
 * This template can be overridden by copying it to your theme directory:
 * your-theme/memberpress-gift-reporter/emails/reminder-email.php
 * 
 * Available variables (same as MemberPress emails):
 * @var string $product_name The name of the gifted product
 * @var string $redemption_link The URL where the recipient can redeem the gift
 * @var string $site_name The name of the website
 * @var string $user_login The user's login name
 * @var string $user_email The user's email address
 * @var string $user_first_name The user's first name
 * @var string $user_last_name The user's last name
 * @var string $blogname The site name
 * 
 * @package MemberPressGiftReporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Default variables if not set (MemberPress style)
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables extracted from array, following WordPress template standards
$product_name    = isset( $product_name ) ? $product_name : '';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables extracted from array, following WordPress template standards
$redemption_link = isset( $redemption_link ) ? $redemption_link : '';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables extracted from array, following WordPress template standards
$site_name       = isset( $site_name ) ? $site_name : ( isset( $blogname ) ? $blogname : get_bloginfo( 'name' ) );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables extracted from array, following WordPress template standards
$blogname        = isset( $blogname ) ? $blogname : ( isset( $site_name ) ? $site_name : get_bloginfo( 'name' ) );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables extracted from array, following WordPress template standards
$user_login      = isset( $user_login ) ? $user_login : '';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables extracted from array, following WordPress template standards
$user_email      = isset( $user_email ) ? $user_email : '';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables extracted from array, following WordPress template standards
$user_first_name = isset( $user_first_name ) ? $user_first_name : '';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables extracted from array, following WordPress template standards
$user_last_name  = isset( $user_last_name ) ? $user_last_name : '';
?>
<div style="font-size: 18px; font-weight: bold; margin-bottom: 20px;"><?php echo esc_html__( 'Hello!', 'memberpress-gift-reporter' ); ?></div>

<p>
	<?php echo esc_html__( 'Just a friendly reminder — the recipient of your gift for', 'memberpress-gift-reporter' ); ?>
	<strong><?php echo esc_html( $product_name ); ?></strong>
	<?php echo esc_html__( 'hasn\'t redeemed it yet.', 'memberpress-gift-reporter' ); ?>
</p>

<div style="background-color: #f3e5f5; padding: 15px; border-radius: 6px; border-left: 4px solid #9c27b0; margin: 20px 0;">
	<strong><?php esc_html_e( 'They can redeem it by visiting:', 'memberpress-gift-reporter' ); ?></strong><br>
	<a href="<?php echo esc_url( $redemption_link ); ?>" style="color: #9c27b0; text-decoration: none; font-weight: bold;"><?php echo esc_html( $redemption_link ); ?></a>
</div>

<p style="font-style: italic; color: #27ae60;"><?php esc_html_e( 'Feel free to share this link with them so they don\'t miss out.', 'memberpress-gift-reporter' ); ?></p>
