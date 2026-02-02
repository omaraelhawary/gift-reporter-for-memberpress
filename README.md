# Gift Reporter for MemberPress

**Note: This is an independent plugin developed by Omar ElHawary. It is not an official MemberPress plugin.**

A comprehensive WordPress plugin that generates detailed reports for the MemberPress Gifting add-on, showing the linkage between gift givers and recipients. This plugin provides advanced filtering, CSV export capabilities, automated reminder emails, and a modern admin interface.

## 📸 Screenshots

### Admin Dashboard
![MemberPress Gift Report Dashboard](screenshots/dashboard.png)

*The MemberPress Gift Report dashboard showing advanced filtering options, summary statistics, and detailed gift transaction data with export functionality.*

## Plugin Information

- **Version:** 1.6.2
- **Requires at least:** WordPress 5.0
- **Tested up to:** WordPress 6.9
- **Requires PHP:** 7.4 or higher
- **License:** GPLv2 or later
- **Author:** Omar ElHawary
- **Tags:** memberpress, gifting, reports, csv export, reminders, analytics

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- MemberPress plugin (active)
- MemberPress Gifting add-on (active)
- MySQL 5.7+ or MariaDB 10.2+

## 🎁 Features

- **Complete Gift Tracking**: Track gift purchases, redemptions, and status
- **Quick Actions**: Built-in action buttons for each gift transaction
  - 📧 Resend gift email to the gifter
  - 🔗 Copy redemption link to clipboard
- **Bulk Operations**: Manage multiple gifts at once
  - Select all unclaimed gifts for bulk operations
  - Bulk resend reminder emails to multiple gifters
  - Batch processing with progress tracking
- **Automatic Reminder System**: Automated email reminders for unclaimed gifts
  - Daily cron schedule for efficient processing
  - Multiple customizable reminder schedules (hours or days)
  - Fully customizable email templates with variable support
  - Test email functionality to preview emails
  - Theme override support for email templates
- **Advanced Filtering System**: 10 powerful filters for precise data analysis
  - Date range filtering (purchase and redemption dates)
  - Gift status filtering (claimed/unclaimed)
  - Product/membership filtering
  - Email filtering (gifter and recipient)
  - Transaction ID filtering (purchase and claim transactions)
- **Smart Data Detection**: Intelligent messaging for no-data scenarios
- **Comprehensive Reports**: View detailed gift transaction data
- **Filtered CSV Export**: Export only filtered data, not all data
- **REST API**: Programmatic access to report data
- **Modern Admin Interface**: Clean, responsive, and user-friendly dashboard with tabbed navigation
- **Mobile Optimized**: Touch-friendly interface for all devices
- **Security**: Admin-only access with proper permissions

## 🚀 Installation

1. Download the plugin files
2. Upload the `memberpress-gift-reporter` folder to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Go to **MemberPress** → **Gift Report** to view reports

## 📊 Usage

### Admin Dashboard

The plugin has two main tabs: **Gift Report** and **Reminders**.

#### Gift Report Tab

1. Go to **WordPress Admin** → **MemberPress** → **Gift Report** (Report tab)
2. Use the advanced filtering system to narrow down your data:
   - **Date Filters**: Filter by purchase date range
   - **Status Filters**: Filter by claimed/unclaimed status
   - **Product Filters**: Filter by specific memberships
   - **Email Filters**: Search by gifter or recipient email
   - **Transaction ID Filters**: Search by purchase or claim transaction ID
   - **Redemption Filters**: Filter by when gifts were claimed
3. View summary statistics and detailed gift data
4. Use action buttons in the **Actions** column:
   - 📧 **Resend Email**: Click to resend the gift email to the gifter
   - 🔗 **Copy Link**: Click to copy the redemption link to your clipboard
5. For bulk operations:
   - Use **Select All Unclaimed** to quickly select all unclaimed gifts
   - Click **Send Reminder Emails to Selected** to send emails to multiple gifters at once
6. Click **Download CSV Report** to export filtered data
7. Use **Clear Filters** to reset all filters quickly

#### Reminders Tab

1. Go to **WordPress Admin** → **MemberPress** → **Gift Report** → **Reminders** tab
2. Enable automatic reminders by checking **Enable Automatic Reminders**
3. Configure reminder schedules:
   - Add multiple reminder schedules (hours or days after purchase)
   - Each schedule can have different delays (e.g., 7 days, 14 days, 30 days)
   - Reminders are sent automatically via daily cron job
4. Customize email content:
   - **Email Subject**: Customize the subject line with variables
   - **Email Body**: Use the rich text editor to customize the email content
   - Available variables: `{$product_name}`, `{$redemption_link}`, `{$site_name}`, `{$user_email}`, `{$user_first_name}`, etc.
5. Test your email:
   - Click **Send Test Email** to preview how the email will look
   - Enter a test email address and send a sample email
6. Click **Save Settings** to apply your changes

### REST API

Get report data programmatically:

```php
// Get report data
$response = wp_remote_get(home_url('/wp-json/mpgr/v1/report'));

// Export CSV
$response = wp_remote_post(home_url('/wp-json/mpgr/v1/export'));
```

## 📈 Report Data

The plugin tracks and reports on:

### Gift Purchase Information
- Transaction ID and number
- Purchase date and amount
- Gifter details (user ID, email, name)

### Product Information
- Product ID and name
- Gifted membership details

### Coupon Information
- Generated coupon code
- Coupon ID and status

### Redemption Information
- Recipient details (user ID, email, name)
- Redemption date and transaction
- Gift status (claimed/unclaimed/invalid)

### Summary Statistics
- Total gifts purchased (filtered)
- Claimed vs unclaimed gifts (filtered)
- Claim rate percentage (filtered)
- Total revenue generated (filtered)

### Advanced Filtering
- **Date Range Filtering**: Filter by purchase or redemption dates
- **Status Filtering**: Filter by gift status (claimed/unclaimed)
- **Product Filtering**: Filter by specific memberships
- **Email Filtering**: Search by gifter or recipient email addresses
- **Transaction ID Filtering**: Search by purchase transaction ID or claim transaction ID
- **Combined Filtering**: Use multiple filters simultaneously for precise data analysis

## 🔧 Configuration

### Customization

You can customize the plugin by:

1. **Styling**: Modify `assets/css/style.css`
2. **Functionality**: Extend the `MPGR_Gift_Report` class
3. **Admin Interface**: Customize `includes/class-admin.php`
4. **Email Templates**: Override email templates in your theme (see below)

### Email Template Overrides

You can customize the reminder email template by copying it to your theme directory. This allows you to modify the email content, styling, and layout without losing your changes when the plugin updates.

#### How to Override the Reminder Email Template

1. **Copy the template file** to your theme directory:
   ```
   Copy from: wp-content/plugins/memberpress-gift-reporter/views/emails/reminder-email.php
   Copy to:   wp-content/themes/your-theme/memberpress-gift-reporter/emails/reminder-email.php
   ```

2. **Create the directory structure** in your theme:
   - Create a folder: `memberpress-gift-reporter`
   - Inside that, create a folder: `emails`
   - Place the template file: `reminder-email.php`

3. **Customize the template** to your needs. The template receives these variables (MemberPress style format):
   - `{$product_name}` - The name of the gifted product/membership
   - `{$redemption_link}` - The URL where recipients can redeem the gift
   - `{$site_name}` or `{$blogname}` - The name of your website
   - `{$user_login}` - The gifter's username
   - `{$user_email}` - The gifter's email address
   - `{$user_first_name}` - The gifter's first name
   - `{$user_last_name}` - The gifter's last name

#### Example Override

**Path:** `wp-content/themes/your-theme/memberpress-gift-reporter/emails/reminder-email.php`

```php
<?php
/**
 * Custom Reminder Email Template
 * This file overrides the default plugin template
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div style="font-size: 18px; font-weight: bold; margin-bottom: 20px;">
    Hello <?php echo esc_html( $user_first_name ? $user_first_name : 'there' ); ?>!
</div>

<p>You have purchased a gift membership for <strong><?php echo esc_html( $product_name ); ?></strong>.</p>

<div style="background-color: #f3e5f5; padding: 15px; border-radius: 6px; border-left: 4px solid #9c27b0; margin: 20px 0;">
    <strong>The recipient can redeem this gift by visiting:</strong><br>
    <a href="<?php echo esc_url( $redemption_link ); ?>" style="color: #9c27b0; text-decoration: none; font-weight: bold;">
        <?php echo esc_html( $redemption_link ); ?>
    </a>
</div>

<p style="font-style: italic; color: #27ae60;">Thank you for your purchase!</p>

<div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e9ecef; color: #6c757d; font-size: 14px;">
    <p>Best regards,<br>
    <strong><?php echo esc_html( $site_name ); ?></strong></p>
</div>
```

**Note:** The email header and footer are automatically included by the plugin. The template file should contain only the body content between the header and footer.

#### Child Theme Support

If you're using a child theme, the plugin will check in this order:
1. Child theme directory: `your-child-theme/memberpress-gift-reporter/emails/reminder-email.php`
2. Parent theme directory: `your-parent-theme/memberpress-gift-reporter/emails/reminder-email.php`
3. Plugin directory: `memberpress-gift-reporter/views/emails/reminder-email.php` (default)

This ensures your customizations persist even after plugin updates!

#### Email Header Template Override

You can also override the email header template:
- Copy `views/emails/reminder-email-header.php` to `your-theme/memberpress-gift-reporter/emails/reminder-email-header.php`

## 🔒 Security

- **Admin-only access**: Reports require `manage_options` capability
- **Nonce verification**: All AJAX requests are secured
- **Data sanitization**: All user inputs are sanitized
- **SQL preparation**: All database queries use prepared statements

## 🐛 Troubleshooting

### No Data Appears

1. **Check MemberPress**: Ensure MemberPress is active
2. **Check Gifting Add-on**: Verify MemberPress Gifting is active
3. **Check Permissions**: Ensure you have admin access
4. **Check Database**: Verify gift transactions exist

### Export Issues

1. **Check File Permissions**: Ensure PHP can write to temp directory
2. **Check Memory Limit**: Large datasets may require more memory
3. **Check Timeout**: Long-running exports may timeout

### Reminder Email Issues

1. **Check Cron Jobs**: Verify WP-Cron is working (check if scheduled tasks run)
2. **Check Email Settings**: Ensure WordPress email is configured correctly
3. **Check Reminder Settings**: Verify reminders are enabled in the Reminders tab
4. **Check Reminder Schedules**: Ensure at least one reminder schedule is configured
5. **Test Email**: Use the "Send Test Email" button to verify email delivery
6. **Check Email Template**: Verify the email template file exists and is readable

### Styling Issues

1. **Clear Cache**: Clear any caching plugins
2. **Check CSS**: Verify CSS files are loading
3. **Check Conflicts**: Disable other plugins to test

## 📄 License

This plugin is licensed under the GPL v2 or later.

## 📞 Contact

- **Email**: omaraelhawary@gmail.com

## Changelog

Recent versions (full history in [readme.txt](readme.txt) or on [WordPress.org](https://wordpress.org/plugins/memberpress-gift-reporter/#developers)):

- **1.6.2** — Tested up to WordPress 6.9; plugin header updates
- **1.6.1** — Fixed gift redemption links (use product URLs instead of hardcoded path)
- **1.6.0** — Weekly summary emails, configurable schedules, improved cron handling
- **1.5.x** — Daily reminder cron, orphaned hook cleanup, UI fixes
- **1.4.x** — Bulk resend emails, email template overrides
- **1.3.0** — Resend gift email and copy redemption link actions
- **1.2.0** — Transaction ID filters, 10 filters total
- **1.1.0** — Advanced filtering, date/email/product filters, CSV export
- **1.0.0** — Initial release (gift reports, CSV export, REST API)

---

## Important Note

**This plugin is developed and maintained independently by Omar ElHawary. It is not affiliated with, endorsed by, or officially supported by MemberPress.**

This plugin requires MemberPress and the MemberPress Gifting add-on to function properly. For support, feature requests, or bug reports, please contact the plugin developer at omaraelhawary@gmail.com.
