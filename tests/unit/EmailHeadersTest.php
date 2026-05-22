<?php
/**
 * Tests for reminder email headers.
 *
 * @package MemberPressGiftReporter
 */

/**
 * Email headers test case.
 */
class EmailHeadersTest extends MPGR_TestCase {

	/**
	 * From name should be sanitized to prevent header injection.
	 */
	public function test_get_email_headers_strips_crlf_from_from_name() {
		update_option(
			'mpgr_reminder_settings',
			array(
				'from_name'  => "Evil\r\nBcc: attacker@example.com",
				'from_email' => 'sender@example.com',
			)
		);

		$headers = MPGR_Reminders::get_email_headers();

		$this->assertCount( 2, $headers );
		$this->assertStringStartsWith( 'From: ', $headers[1] );
		$this->assertStringNotContainsString( "\r", $headers[1] );
		$this->assertStringNotContainsString( "\n", $headers[1] );
		$this->assertStringNotContainsString( 'Bcc:', $headers[1] );
		$this->assertStringContainsString( 'sender@example.com', $headers[1] );
	}

	/**
	 * Blank from fields should fall back to site defaults.
	 */
	public function test_get_email_headers_uses_site_defaults_when_blank() {
		update_option( 'admin_email', 'admin@example.com' );
		update_option( 'mpgr_reminder_settings', array() );

		$headers = MPGR_Reminders::get_email_headers();

		$this->assertStringContainsString( get_bloginfo( 'name' ), $headers[1] );
		$this->assertStringContainsString( 'admin@example.com', $headers[1] );
	}
}
