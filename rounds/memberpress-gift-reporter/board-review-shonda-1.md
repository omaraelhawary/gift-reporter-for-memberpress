# Board Review #1 — Narrative & Engagement (Shonda Rhimes)

**Gift Reporter for MemberPress v1.7.0**

## Verdict

A **utility procedural**, not a **serialized drama**—appropriate for WP admin, but the best retention beats ship **off by default** and the pilot never promises a season.

## Retention loops

- **Unclaimed gifts** are the unresolved plot; daily cron + multi-schedule reminders (7/14/30 days) close it for **gifters**, not admins.
- **Weekly summary** (Monday 9 AM: claim rate, unclaimed, revenue, breakdowns, link to Gift Report) is your only “next episode”—and it is opt-in.
- **Report tab:** filters, stats, resend/copy link, bulk “select all unclaimed”—return visits are **event-driven** (stuck gifts), not habit.
- **REST API** serves integrators; it does not build operator habit.

## Engagement hooks

- **Works:** Claim rate vs. unclaimed, bulk reminders, weekly badges, test-email on Reminders. **Missing:** No login “cold open” or dashboard pulse—the story hides in a submenu.

## Onboarding & sequencing

- **Pilot:** Activate → MemberPress → Gift Report. No wizard, no prompt to enable reminders or weekly summary.
- **Good order:** Report (problem) before Reminders (automation). **Bad default:** both automations disabled at activation—Act 3 behind checkboxes.
- **Test email** is strong second-act tooling most users never reach without a guide.

## What brings them back?
- **Admins:** Monday summary + unclaimed guilt + bulk send—not daily plugin use.
- **Support:** Filtered table, resend, copy link, CSV when someone emails.
- **Ship recommendation:** Default or prompt weekly summary; surface unclaimed count at login; end first report with one cliffhanger—“Enable day-7 reminders?”—not another blank settings screen.
