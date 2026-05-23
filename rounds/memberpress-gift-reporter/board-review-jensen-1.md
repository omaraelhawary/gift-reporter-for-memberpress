# Board Review #1 — Jensen Huang · Gift Reporter v1.7.0 (May 2026)

## Verdict
Strong **operations layer** on someone else's platform — not a platform yet. Speed and completeness win the niche today; no compounding data moat yet.

## Platform economics
- **CUDA-on-NVIDIA**: WordPress.org distributes; **Caseproof owns the stack**. GPLv2 free listing = acquisition, not revenue, unless a Pro tier follows.
- Mini-platform shape is right: 10 filters, CSV, REST (`mpgr/v1`), theme email overrides, cron reminders, bulk queue (`mpgr_send_queued_gift_email`). **Extensibility is your CUDA**.
- **Existential dependency**: native reporting in MemberPress Gifting compresses this category — become the indispensable default before they ship it.

## Data moat
- Gift facts live in **`mepr_transactions`** (heavy JOINs in `get_report_joins_sql()`); no owned tables → **low structural moat** on reporting alone.
- Stickiness is **operational state**: `_mpgr_reminder_sent_count`, `_mpgr_last_reminder_ts`, schedules, templates, weekly summary habit — workflow memory, not network data.
- No cross-site benchmarks (correct for privacy); moat is per-site config + reminder history.

## Competitive positioning
- Likely own **"MemberPress gift reporting" on WordPress.org** — small TAM, weak substitutes (manual email, spreadsheets).
- "Independent, not official" builds trust but **limits MemberPress co-marketing**.
- REST (`manage_options` + `wp_rest` nonce, rate-limited) suits admin UI; **headless SaaS needs Application Passwords / documented server auth**.

## Technical strategy (30 days)
- 1.6→1.7 + PHPUnit + `mpgr_cron_migrated_v1_6_4` = **maturation discipline** (good).
- **Scale**: JOIN cost on large `mepr_transactions` beats feature count as the next pain.
- **Reliability**: daily WP-Cron — failed cron = lost gift revenue; Action Scheduler or a "missed reminders" notice would differentiate.

## One recommendation
**Ship 2–3 signed outbound webhooks** (`gift_unclaimed_after_schedule`, `gift_claimed`, `weekly_summary_sent`). Turns reminder meta + filters into a **Zapier/Make platform** — fastest ecosystem lock-in without competing with Caseproof on core gifting.
