# Season 2 Roadmap — Gift Reporter for MemberPress

**Showrunner:** Shonda Rhimes (Narrative & Engagement)  
**Date:** May 23, 2026  
**Informed by:** Board Verdict #1, Jensen, Oprah, Warren, and Board Review #1 (Shonda)

---

## The series bible (one paragraph)

Season 1 is a **utility procedural**: admins show up when a gift is stuck, run the report, maybe bulk-send or export CSV, then leave. That is a fine guest appearance, not a series. Season 2 turns Gift Reporter into a **serialized ops drama** where the unresolved plot is always **unclaimed gifts = stranded revenue**, the **gifters** are characters you nudge off-screen (7/14/30-day reminders to the gifter, not the recipient), and the **admin** is the showrunner who gets a **cold open every login**, a **Monday episode** (weekly summary), and a **cliffhanger every first visit** (“Enable day-7 reminders before these three gifts go cold?”). Episode 2 is not “open MemberPress submenu again”—it is **something changed since you last looked**, and you need to see what.

The board already ordered the pilot fix: welcome banner + three CTAs on Gift Report. Season 2 is what happens **after** that pilot—features that make “what happens next?” unavoidable.

---

## Retention hooks (Season 2)

### 1. Login cold open — unclaimed count in their face

**Pitch:** On every wp-admin load, show a dismissible admin-bar badge: **“Gift Reporter · 12 unclaimed ($1,497 at risk)”** linking to Gift Report with the **Unclaimed** status filter pre-applied.

**Why it works:** Right now the story lives in **MemberPress → Gift Report**, a submenu graveyard. Retention dies in the gap between “I should check gifts” and “I remembered where the plugin lives.” A cold open creates **narrative tension before Act 1**—the same pull as a “Previously on…” sting. Unclaimed count is already computed for stats and weekly summary; surfacing it at login turns event-driven panic into **ambient unfinished business**. Oprah gets clarity; Shonda gets the hook; Warren gets a reason to return without opening email.

---

### 2. Monday Pulse — make the weekly summary the title card

**Pitch:** After first successful Gift Report view, prompt: **“Get next Monday’s episode—claim rate, unclaimed count, revenue, product breakdown, one click to Gift Report.”** Default-on for new activations (with honest opt-out), reuse `MPGR_Weekly_Summary` Monday 9 AM cron, and add a **“Send me a preview now”** button next to the existing test flow on Reminders.

**Why it works:** The weekly summary is the only feature that already behaves like **“see you next week.”** It ships **disabled** (`mpgr_weekly_summary_settings` → `enabled: false`), so most installs never get a second episode. Serialization requires a **recurring premiere**—claim rate vs. last week, unclaimed badges, product rows—not another trip to ten filters. The email is the trailer; the report tab is the full episode. This is intrinsic retention (the number changed) not nagging.

---

### 3. Day-7 cliffhanger — end the first report with one question

**Pitch:** When an admin leaves Gift Report (or after first activation), show a single modal/card: **“You have {N} unclaimed gifts. {M} will hit day 7 this week. Turn on automatic reminders to gifters?”** One primary button → Reminders tab with **Enable Automatic Reminders** focused; secondary → “Not now.”

**Why it works:** Season 1’s pilot ends on a **blank settings screen** and reminders **off by default**—Act 3 is behind checkboxes nobody checks. The cliffhanger is not “here are 10 filters”; it is **time-bomb stakes** tied to schedules you already ship (7/14/30 days, daily `run_scheduled_reminders`, gifter-facing copy in `reminder-email.php`). The admin’s emotional need is **“I don’t want to be the villain who let gifts die”**; automation is the ally. Bulk **Send Reminder Emails to Selected** becomes the manual override when they can’t wait for cron—same story, two speeds.

---

### 4. Stuck Gifts arcs — aging buckets as ongoing “cases”

**Pitch:** Add a **Stuck Gifts** strip above the filter row on Gift Report: three clickable arcs—**7–14 days unclaimed**, **14–30 days**, **30+**—each showing count + optional revenue, plus one CTA **“Select all in this arc → bulk remind.”**

**Why it works:** The preset **“Unclaimed > 7 days”** is already the best detective beat in the product, but it is buried in a dropdown. TV retention uses **A-story / B-story / C-story** lanes, not one pile of evidence. Aging buckets give admins a **reason to return mid-week** (“Act 2 got worse”) without waiting for Monday or a support email. Pair with `_mpgr_reminder_sent_count` / `_mpgr_last_reminder_ts` later (“reminded twice, still unclaimed”) for true serialized cases—Season 2.5, but the buckets alone create **progressive tension**.

---

### 5. Recovery reel — show what improved since last visit

**Pitch:** At the top of Gift Report stats, a one-line **Recovery reel**: **“Since your last visit: 4 gifts claimed · $398 recovered · claim rate 62% → 68%.”** Store last-seen snapshot in a site option on each report load; weekly summary can echo the same arc for the email cold open.

**Why it works:** Utility tools churn because they only deliver **guilt** (unclaimed) without **payoff** (wins). Grey’s doesn’t run 20 years on deaths alone—it runs on **“she lived.”** Claim rate, claimed revenue, and bulk sends are victories; if the UI only screams problems, admins avoid the theater. A recovery reel gives **pride and curiosity**—“did my reminders work?”—which is stronger than another CSV export. It also sets up Season 3 word-of-mouth: *“This plugin told me we recovered four gifts last week.”*

---

## What we are NOT greenlighting (Season 2 bibles)

- **More filters as “content.”** Ten filters are competence porn, not a cliffhanger. Depth stays; drama moves to defaults, badges, and arcs.
- **REST as retention.** `mpgr/v1` is for integrators (Jensen’s platform play)—not why a shop owner opens wp-admin on Tuesday.
- **Reminders to recipients by default.** The product’s voice is warm in `reminder-email.php` because it talks to **gifters**; confusing that breaks trust (Oprah’s note) and kills the B-plot.

---

## Season 2 premiere order (production)

1. **Login cold open** + board **welcome banner** (same sprint—pilot + title card).  
2. **Day-7 cliffhanger** on first report exit (one question, not a wizard).  
3. **Monday Pulse** opt-in prompt + preview send (flip default for new installs when support story is ready).  
4. **Stuck Gifts** arcs (reuse filter SQL, minimal new UI).  
5. **Recovery reel** (snapshot option + diff on stats).

---

## The question every writers’ room asks

**“What makes them need episode 2?”**

Not another feature list. **Something unresolved that will get worse or better while they are away**—unclaimed count on the admin bar, gifts crossing day 7, Monday’s numbers dropping into their inbox, a stuck-gift arc turning red, or proof that last week’s reminders **worked**. Gift Reporter already has the cast and the cron; Season 2 is finally **shooting the scenes that end on a cut, not a fade to settings.**

---

*Season 2 Roadmap · Shonda Rhimes persona · Gift Reporter for MemberPress*
