# SocialDesk — One-Page Client Guide

*The whole system on one page for the October 2026 build. The complete reference is `CLIENT_FLOW.md`.*

**What it is.** One shared inbox for WhatsApp, Instagram and Messenger. Customers message your
business; your team replies from one screen, organises the lead, and can follow up with approved
WhatsApp templates, saved audiences and automations.

---

## The one rule that shapes everything

You can send free-form text **only within 24 hours** of the customer's last message. After that,
WhatsApp requires a **pre-approved template**. Every new inbound message restarts the clock.

The Inbox composer is **free-form text only** — there is no template button. Outside the window,
send the template through a **WhatsApp broadcast** or a **follow-up sequence**.

A customer who replies exactly **STOP** or **UNSUBSCRIBE** is opted out. Broadcasts and sequences
skip them from then on; there is no self-serve way to opt them back in.

## Your daily loop

1. **Unified Inbox** — answer conversations; add tags, move the stage, write internal notes.
2. **Templates** — create or sync approved WhatsApp templates for follow-up.
3. **Campaigns** — send a template to an **Audience**.
4. **Automations** — let keywords, routing and quiet-thread follow-ups handle the repetitive work.

## The words you'll meet

| Word | What it means |
| --- | --- |
| **Unified Inbox** | Live conversations across all connected channels |
| **Lead CRM** | Contacts, consent, tags, pipeline stage and CSV export |
| **Audiences** | Saved groups of opted-in contacts, filtered by tag, stage, channel or last interaction |
| **Templates** | Pre-approved WhatsApp messages usable outside the 24-hour window |
| **Campaigns** | A WhatsApp broadcast (template + audience + optional schedule), or a social funnel record |
| **Automations** | Auto-reply to a keyword, route a new chat, or follow up when a chat goes quiet |
| **Wallet** | Prepaid balance that pays for template messages |

Also in the sidebar: **Analytics**, **Billing**, **Channels**, **Team** (owner/admin only).

## What works today

- WhatsApp, Instagram and Messenger **inbound** into one Inbox.
- Free-form replies **inside** the 24-hour window.
- Contact capture, tags, five pipeline stages, internal notes, quick replies, resolve/reopen.
- Building and syncing WhatsApp templates; Meta approval status and notifications.
- **WhatsApp template broadcasts** to opted-in contacts, with wallet debits and delivery tracking.
- Saved audiences, keyword auto-replies, round-robin routing and follow-up sequences.
- Wallet balance, invoices, team invitations, plan limits and in-app notifications.
- Analytics for messages, first response, audience and wallet spend (with the caveats below).

## What is not automatic — read before a demo or pilot

| Item | Reality |
| --- | --- |
| **Instagram comment-to-DM campaign** | The campaign and trigger rows are created, but no DM is sent. **UI only.** |
| **Messenger ad-click welcome** | The campaign and trigger rows are created, but no welcome is sent. **UI only.** |
| **Campaign “Responses” and “Leads Won”** | Never increment. Delivered counts do. Do not report them as results. |
| **Inbox template send / attachments** | Not available. Text and internal notes only. |
| **“Enroll in campaign” in the Inbox** | A placeholder toast; no enrollment. |
| **Template pause/delete buttons** | Known UI bug: the controls are missing. Ask support. |
| **Plan upgrade/downgrade** | No self-serve purchase. The Upgrade link lands on Billing with no purchase button. |
| **After trial expiry** | Product routes redirect to Billing; a SocialDesk admin must extend the trial/paid period or provision the plan outside the workspace. |
| **MAC overage** | Displayed as an estimate, not charged automatically. |
| **Scheduled send time** | The form treats the typed time as **UTC**, not local time. In India, enter IST minus 5:30 (e.g. 04:30 for a 10:00 send). |
| **Manual contact assignment / agent availability** | No UI. The inbound round-robin router and optional routing rules assign; everyone is available to the default router. |
| **Contact edit/delete/import** | Not available. Add and export only; stage/tags are edited in the Inbox. |
| **A manually added or Instagram/Messenger-only contact** | The manually added one has no channel identity; the other has no WhatsApp identity. A WhatsApp broadcast cannot deliver to either. |
| **Workspace rename/delete/settings** | No screen in this build. |
| **Dashboard** | Starter placeholder; not the product home. Login goes to the Inbox. |
| **WhatsApp Embedded Signup** | Disabled until SocialDesk configures it. Use the manual token form. |
| **Inbox real-time** | It polls about every 15 seconds; it is instant only if Reverb is configured. |

## The wallet

| Send | Cost |
| --- | --- |
| Free-form reply inside the 24-hour window | Free |
| Marketing template | 88 paise |
| Utility or authentication template | 30 paise |

The wallet warns owners/admins when it crosses below **₹50**. A permanent delivery failure is
refunded once.

> **Do not use the current top-up with real money.** Unless SocialDesk has configured Razorpay,
> **Add Balance** records a manual test payment, credits the wallet instantly and collects nothing.
> Once Razorpay keys are configured, that manual path is disabled and a live checkout must still be
> wired before clients can pay online. GST is shown at 18%. Any workspace member can currently open
> Billing and record a top-up, so do not run a paid pilot with untrusted users until the gateway and
> permissions are in place.

## Who can do what — the honest version

| Role | Can do |
| --- | --- |
| **Owner** | Everything; Team; create templates; Embedded Signup (when enabled); sees the webhook verify token |
| **Admin** | Day-to-day work; Team (agents only); create templates; cannot manage the owner or other admins |
| **Agent** | Inbox, Lead CRM, Campaigns, Audiences, Automations, Analytics; view/sync templates; no Team screen |

**Role caution:** the main role gates are Team, template authoring, WhatsApp Embedded Signup, the
owner/super-admin webhook token and the inbound simulator. An agent can still connect/disconnect channels,
create campaigns and automations, and open Billing/top up. The owner can never be removed or demoted.

## Set-up checklist (once)

1. **Channels** — manually connect the WhatsApp number, Instagram account and/or Facebook Page with
   the Meta asset ID and access token. Run **Test connection** on each.
2. **Meta webhook** — SocialDesk must confirm that the shared platform Meta app points at the
   callback URL, is subscribed to **messages**, and has the app secret configured. You do not create
   a separate Meta app for the workspace.
3. **Templates** — create one in SocialDesk (submits to Meta) or in Meta Business Manager, then
   **Sync from Meta**. Wait for **Approved**.
4. **Team** — invite staff; the invite link is valid for 7 days and is shown once to the inviter.
5. **Business hours** — set the timezone and hours if using out-of-hours automations.
6. **Wallet** — fund it through SocialDesk (not the test top-up) for a paid pilot.
7. **Test end to end** — send a real inbound message, reply in the Inbox, then run a tiny broadcast.

## Five things worth knowing

1. The Inbox polls every ~15 seconds. Outbound sends depend on the platform's queue worker and
   scheduler, which SocialDesk operates in production.
2. **Meta controls template approval and review time.** SocialDesk cannot promise a review window.
   Press **Sync from Meta** to refresh the status.
3. **WhatsApp connection is manual in this build.** Embedded Signup is disabled unless SocialDesk
   configures the platform Meta app; the manual ID + token form remains available as the fallback.
4. Instagram and Messenger allow free-form replies only inside 24 hours, exactly like WhatsApp —
   and this build has no template path for them outside the window.
5. Invitation and password-reset emails depend on mail being configured. If an invite email does not
   arrive, copy the link shown after sending it and share it directly.

## If something looks wrong

1. **Refresh** — the Inbox is polled, so a stale tab is a common cause.
2. **Check Channels** — a **Needs reconnect** status stops outbound sends.
3. **Check Billing** — a low wallet is the most common reason a template send is refused.
4. **Check the 24-hour banner** — free-form text is blocked once the window closes.
5. **Send the screenshot, the customer's number and the time** to your SocialDesk contact.

> **Support:** ______________________
