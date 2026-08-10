=== Gift Reporter for MemberPress ===
Contributors: omarelhawary
Tags: memberpress, gifting, reports, csv export, reminders
Requires at least: 5.0
Requires PHP: 7.4
Tested up to: 7.0
Stable tag: 1.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reporting plugin for MemberPress Gifting. Track gift purchases and redemptions, export to CSV, and send automated reminder emails.

== Description ==

**Independent plugin by Omar ElHawary. Not an official MemberPress product.**

Extends MemberPress Gifting with reporting and management: gift tracking, filtering, CSV export, bulk actions, automated reminders, and a REST API.

= Features =

* Gift tracking with 11 filters (dates, status, product, email, coupon code, transaction ID)
* Resend gift email and copy redemption link per gift
* Bulk select unclaimed gifts and send reminder emails
* Automated reminder emails with customizable schedules and templates
* Optional weekly summary emails for admins
* Filtered CSV export and a paginated REST API
* Admin-only; requires MemberPress + MemberPress Gifting

== Installation ==

1. Upload the `memberpress-gift-reporter` folder to `/wp-content/plugins/`
2. Activate under **Plugins**
3. Open **MemberPress** → **Gift Report** to use

== Frequently Asked Questions ==

= Do I need MemberPress? =

Yes. MemberPress and the MemberPress Gifting add-on must be active.

= Is this official MemberPress? =

No. Developed independently by Omar ElHawary; not affiliated with MemberPress.

= How do I customize reminder emails? =

Use the **Reminders** tab for subject and body. To override the template, copy `views/emails/reminder-email.php` to `your-theme/memberpress-gift-reporter/emails/reminder-email.php`.

= How do I export data? =

Apply filters on the Gift Report tab, then click **Download CSV Report**. Only filtered rows are exported.

== Changelog ==

= 1.9.0 =
* Fixed: refunded gifts are no longer counted as revenue or as unclaimed, are shown as their own status, and can be filtered
* Fixed: refunded gifts are no longer sent reminder emails
* Fixed: REST API now accepts Application Passwords instead of requiring a browser session
* Fixed: uninstall removes the onboarding options, transients and user meta added in 1.8.0
* Fixed: report table labels now honour the site locale instead of always rendering English
* Fixed: reminder From Name is truncated at a line break rather than having it stripped
* Added: filter the report by coupon code
* Added: average time-to-claim on the report and in the weekly email
* Added: per-membership breakdown and a purchases-vs-claims trend on the report
* Added: per-gift reminder send log, including failures, surfaced in the report and weekly email
* Added: REST report pagination (page/per_page, total, total_pages, X-WP-Total headers)
* Performance: summary queries are cached, cutting repeated report queries on large sites
* Internal: test suite no longer exits early and now covers the 0-delay reminder branching

= 1.8.1 =
* Remove development files from the distributed plugin package (rounds/, bin/)

= 1.8.0 =
* Tested up to WordPress 7.0
* Welcome banner, admin-bar unclaimed pulse, day-7 cliffhanger, Monday Pulse weekly summary prompt, recovery reel, and Stuck Gifts aging arcs
* Bulk remind shortcut from Stuck Gifts (auto-select unclaimed rows on filtered report)
* Clearer Reminders tab copy (gifter vs recipient; collapsible email variable docs)
* Weekly summary enabled by default on new installs; preview send from Gift Report
* Onboarding assets and unit test coverage for retention features

= 1.7.0 =
* Tested up to WordPress 7.0
* Add customizable From Name and From Email for reminder, resend, bulk, and weekly summary emails
* Centralize report filter sanitization and improve CSV export safety
* Refactor cron scheduling and cleanup on upgrade/uninstall
* Improve admin table styling, pagination, and export/resend button feedback

= 1.6.3 =
* Fix WordPress 6.7+ notice: load translations on init; avoid translating cron schedule label before init

= 1.6.2 =
* Tested up to WordPress 6.9

= 1.6.1 =
* Redemption links now use product URLs instead of hardcoded paths

== Support ==

If you need plugin support from us, you can visit our [support page](https://wordpress.org/support/plugin/memberpress-gift-reporter/).

== Plugin Development ==

If you're a theme author, plugin author, or just a code hobbyist, you can follow the development of this plugin on its [GitHub repository](https://github.com/omaraelhawary/gift-reporter-for-memberpress).

== Rate us ==

Love Gift Reporter for MemberPress? [Rate us on WordPress](https://wordpress.org/support/plugin/memberpress-gift-reporter/reviews/) 🙂
