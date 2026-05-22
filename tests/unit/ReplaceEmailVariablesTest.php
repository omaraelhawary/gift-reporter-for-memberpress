<?php
/**
 * Tests for MPGR_Reminders email helpers.
 *
 * @package MemberPressGiftReporter
 */

/**
 * Email variable replacement test case.
 */
class ReplaceEmailVariablesTest extends MPGR_TestCase {

	/**
	 * MemberPress-style variables should be replaced and escaped.
	 */
	public function test_replace_email_variables_escapes_text_and_urls() {
		$content = '<p>Hello {$user_first_name}, redeem at {$redemption_link} for {$product_name}</p>';
		$vars    = array(
			'user_first_name' => '<script>alert(1)</script>',
			'redemption_link' => 'https://example.com/register?coupon=ABC<script>',
			'product_name'    => 'Premium & Plan',
		);

		$result = MPGR_Reminders::replace_email_variables( $content, $vars );

		$this->assertStringNotContainsString( '<script>', $result );
		$this->assertStringContainsString( 'Premium &amp; Plan', $result );
		$this->assertStringContainsString( 'https://example.com/register?coupon=ABC', $result );
	}

	/**
	 * Brace-only variables should remain supported for backward compatibility.
	 */
	public function test_replace_email_variables_supports_legacy_brace_syntax() {
		$result = MPGR_Reminders::replace_email_variables(
			'Gift: {product_name}',
			array( 'product_name' => 'Annual Membership' )
		);

		$this->assertSame( 'Gift: Annual Membership', $result );
	}
}
