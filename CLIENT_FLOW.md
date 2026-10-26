# SocialDesk — Complete Client Guide

*A plain-language guide to the whole portal: what it does, how a client uses it, and what is genuinely automatic today.*

**Applies to:** the October 2026 SocialDesk build.

**How to read the status labels.** Every feature below is marked as one of:

| Label | Meaning |
| --- | --- |
| **Works today** | The full flow works end to end. |
| **Partial** | The flow works, but with an important limit explained in the section. |
| **UI only** | The screen or button exists, but no background action happens. |
| **Not available** | There is no working screen or API for it in this build. |

> **Please read this first.** This guide describes the product honestly, including the places where a button is a placeholder. It is not a sales brochure. Where a feature is not wired yet, saying so here is deliberate: it prevents surprises during a client demo or pilot.

---

## Contents

1. [What SocialDesk is](#1-what-socialdesk-is)
2. [The 24-hour rule that shapes everything](#2-the-24-hour-rule-that-shapes-everything)
3. [The complete journey at a glance](#3-the-complete-journey-at-a-glance)
4. [Signing up, trials and first login](#4-signing-up-trials-and-first-login)
5. [The interface map](#5-the-interface-map)
6. [Channels: WhatsApp, Instagram and Messenger](#6-channels-whatsapp-instagram-and-messenger)
7. [Inbox: the daily workspace](#7-inbox-the-daily-workspace)
8. [Lead CRM: the contact directory](#8-lead-crm-the-contact-directory)
9. [Templates: messages Meta must approve](#9-templates-messages-meta-must-approve)
10. [Audiences: saved groups of contacts](#10-audiences-saved-groups-of-contacts)
11. [Campaigns: broadcasts and social funnels](#11-campaigns-broadcasts-and-social-funnels)
12. [Automations: auto-replies, routing and follow-ups](#12-automations-auto-replies-routing-and-follow-ups)
13. [Analytics](#13-analytics)
14. [Wallet, plans and billing](#14-wallet-plans-and-billing)
15. [Team, roles and invitations](#15-team-roles-and-invitations)
16. [Notifications](#16-notifications)
17. [Profile and account settings](#17-profile-and-account-settings)
18. [Admin console (SocialDesk staff only)](#18-admin-console-socialdesk-staff-only)
19. [What works today vs. what is only a screen](#19-what-works-today-vs-what-is-only-a-screen)
20. [Operating checklist](#20-operating-checklist)
21. [Troubleshooting](#21-troubleshooting)
22. [Glossary](#22-glossary)

---

## 1. What SocialDesk is

SocialDesk is a **shared inbox for customer conversations** on WhatsApp, Instagram and Facebook Messenger. A customer messages one of your connected business accounts. The message arrives in one Inbox. Your team replies from that Inbox, organises the customer, and can later reach them with approved WhatsApp templates, saved audiences, broadcasts and automations.

The core loop is:

```
Customer sends a message
        ↓
Meta sends it to SocialDesk
        ↓
Contact + conversation appear in the Inbox
        ↓
An agent replies (or an automation replies / routes it)
        ↓
The customer is tagged and staged; they enter any saved audience whose rules they match
        ↓
An approved template reaches them outside the 24-hour window
        ↓
Analytics and the wallet show what happened
```

**What it is good at today**

- One inbox across the three Meta channels.
- Capturing a contact automatically from the first inbound message.
- Free-form replies inside the 24-hour customer-service window.
- WhatsApp template broadcasts to opted-in contacts, with wallet billing and delivery tracking.
- Keyword auto-replies, round-robin routing and time-delayed follow-up sequences.
- Saved audiences, a simple pipeline stage, tags, quick replies and CSV export.
- A prepaid wallet, plan quotas and an admin console for SocialDesk staff.

**What it is not (in this build)**

- It is not a bulk-SMS or cold-list marketing tool. WhatsApp broadcasts only target **opted-in** contacts.
- It is not a full helpdesk: there is no SLA engine, ticket escalation matrix or manual agent-assignment screen.
- It is not a live payment gateway yet: the wallet top-up currently records a **manual test payment**. Once Razorpay keys are configured the manual path is disabled, so a live checkout must be wired by SocialDesk before clients can pay online.
- It is not a social-media scheduler, comment manager or ad manager.
- Instagram comment-to-DM and Messenger ad-click funnels can be created, but **they are not executed by a worker yet**. See [Campaigns](#11-campaigns-broadcasts-and-social-funnels).

---

## 2. The 24-hour rule that shapes everything

Meta allows a business to send a **free-form** message only within **24 hours** of the customer's last message. Every new inbound message restarts that clock. Outside the window, WhatsApp requires a **pre-approved template**.

| Situation | WhatsApp | Instagram / Messenger |
| --- | --- | --- |
| Customer messaged in the last 24 hours | Free-form reply allowed; not charged | Free-form reply allowed |
| Customer's last message is older than 24 hours | Free-form blocked; an approved template is required | Free-form blocked; this build has no template path for IG/Messenger |
| Marketing message | Must use an approved marketing template and an opted-in contact | Not available as a campaign in this build |
| Cost | Free-form replies are free; template sends debit the wallet | No wallet charge |

**What this means in practice**

- The Inbox is for **conversation**, not for re-opening a dead thread. Its composer sends free-form text only.
- If the Inbox shows **“24-hour window closed”**, the banner suggests sending an approved template, but the composer has **no template picker**. Use a WhatsApp broadcast or a follow-up sequence to send the template.
- A customer replying **STOP** or **UNSUBSCRIBE** (the whole message, case-insensitive) opts out of marketing. Sequences and broadcasts skip opted-out contacts. Auto-replies also stay silent for them.

> **Known limitation:** the Inbox has no template button and no attachment upload. Only text replies and internal notes are possible there. This is a product gap, not a setting you can turn on.

---

## 3. The complete journey at a glance

1. **Visit the public site** and review the plans.
2. **Create an account** with your name, business name, email and password.
3. SocialDesk creates a **workspace** on a **14-day Growth trial**, with an owner membership, a wallet and five pipeline stages.
4. **Connect a channel** on the Channels screen (WhatsApp, Instagram or Messenger), or use WhatsApp Embedded Signup if SocialDesk has enabled it.
5. **Meta delivers inbound messages** to the shared webhook; SocialDesk matches them to your channel account and workspace.
6. A **contact and conversation are created automatically**. The Inbox shows the unread thread.
7. An agent **replies inside the 24-hour window**, adds a **tag**, moves the **pipeline stage**, writes an **internal note**, or resolves the thread.
8. For follow-up outside the window, the team creates or syncs an **approved WhatsApp template**.
9. Contacts are filtered into an **audience** (all opted-in contacts or a saved filter).
10. A **WhatsApp broadcast** sends the template, debits the wallet per message, and records delivery.
11. **Automations** can answer keywords, route new conversations to an agent, or chase quiet threads with template steps.
12. **Analytics** summarises messages, audience, spend and broadcasts.
13. **Team members** are invited with owner/admin/agent roles. **Billing** shows the plan, usage, wallet and invoices.
14. After the trial, access depends on an active subscription. There is currently **no self-serve plan purchase**; SocialDesk staff must extend the trial/period or provision a paid subscription manually.

---

## 4. Signing up, trials and first login

### 4.1 The public site

The landing page explains the product, shows the pricing table and answers common questions. The main actions are:

- **Start free** — opens registration.
- **Log in** — opens the sign-in page.
- **Go to dashboard** — shown when you are already signed in.
- **See how it works** — jumps to the product explanation on the page.

Pricing is read from the same plan records that Billing uses, so the landing page and the Billing screen should agree.

### 4.2 Current plan table

| Plan | Monthly price (excl. GST) | Monthly active contacts | Instagram accounts | Agent seats | Overage |
| --- | --- | --- | --- | --- | --- |
| **Starter** | ₹1,999 | 500 | 1 | 2 | ₹1,500 per 500 contacts |
| **Growth** | ₹4,999 | 2,500 | 5 | 5 | ₹1,500 per 500 contacts |
| **Scale** | ₹14,499 | 10,000 | 15 | 20 | ₹1,200 per 500 contacts |

GST is **18%**.

> **Commercial note:** in the current seed data, **Starter and Scale are marked as placeholder prices** in the source configuration. Treat the table as “what the app displays” and confirm the final commercial price with SocialDesk before quoting a client. Growth is the plan used for the trial.

### 4.3 Creating an account

Registration asks for:

| Field | Notes |
| --- | --- |
| **Name** | Your personal name. |
| **Business name** | Optional; becomes the workspace name. Leave blank to use your own name. |
| **Email** | Used to sign in and receive invitations. |
| **Password / confirm password** | Standard password confirmation. |

When you submit:

- A **workspace** is created with a URL-safe slug based on the name.
- You become its **owner**.
- A **14-day Growth trial** starts immediately.
- A **wallet** is created with a zero balance.
- Five pipeline stages are created: **New Inquiry, Pricing Inquiry, Qualified, Hot Lead, Won / Customer**.
- You are signed in and taken to **Channels**, with a prompt to connect your first channel.

**Email verification is not enforced in this build.** You can use the workspace immediately; the app does not block you on a verification email.

> **Trial note:** creating another workspace later starts a **separate** 14-day Growth trial and a separate wallet. Trials are per workspace, not per person.

### 4.4 First login and the “dashboard”

Signing in sends you to your **Inbox**. The `/dashboard` route also redirects to the Inbox (or to “create a workspace” if you have none).

There is a Laravel starter “Dashboard” page in the code, but it is **not** the product home. It is a placeholder and is not linked from the SocialDesk navigation. Do not use it in a client demo as an overview screen.

### 4.5 Creating and switching workspaces

- When you belong to more than one workspace, the account menu shows a **Switch workspace** list.
- **Create workspace** asks for a business name and creates a new trial workspace.
- Each workspace has its own channels, contacts, conversations, audiences, campaigns, automations, wallet and plan.
- Your login is shared across workspaces; the data is not.

### 4.6 What happens when the trial ends

- While the subscription is `trialing` and the trial date is in the future, the product is accessible.
- Once the trial has expired, product screens redirect to **Billing**. Billing itself stays reachable.
- There is **no self-serve purchase or plan-change flow** in this build. A SocialDesk admin can extend the trial or the paid period from the admin console. For a real client, agree a commercial process with SocialDesk before the trial ends.

---

## 5. The interface map

The left navigation (or the mobile menu) contains the product areas. The header contains the workspace name, connected-channel badges (or a “No channels connected” prompt), the **Monthly Active Leads** usage meter with its Upgrade button, the notification bell and the account menu (Switch workspace when you have more than one, Create workspace, Profile, Log out).

| Screen | What it is for | Who can open it in this build |
| --- | --- | --- |
| **Inbox** | Live conversations, replies, notes, tags and stages. | All workspace members |
| **Lead CRM** | Contact directory, filters, add contact, CSV export. | All members |
| **Campaigns** | WhatsApp broadcasts and social funnel campaigns. | All members |
| **Audiences** | Saved contact filters used by broadcasts. | All members |
| **Templates** | Create/sync WhatsApp templates and see approval status. | All members can view/sync; create requires owner or admin; pause/delete controls are hidden in this build |
| **Automations** | Auto-replies, routing rules, follow-up sequences and business hours. | All members |
| **Analytics** | 7/30/90-day messaging, audience, spend and broadcast summary. | All members |
| **Channels** | Connect and manage WhatsApp, Instagram and Messenger accounts. | All members; the webhook verify token is owner/super-admin only |
| **Team** | Members, roles, invitations and seats. | Owners and admins |
| **Billing** | Plan, quota, wallet, top-up, invoices. | All members |
| **Notifications** | In-app notification list. | All members (each sees their own) |
| **Profile** | Name, email, password and account deletion. | Signed-in user |
| **Admin** | Platform overview, workspaces, plans, webhook log. | SocialDesk super admins only |

> **Role caution:** the only role gates are Team management, template authoring, WhatsApp Embedded Signup, the owner/super-admin webhook verify token and the inbound simulator. Everything else — channels, campaigns, automations, Billing and wallet top-up — is open to any workspace member. Treat agent accounts as trusted staff until SocialDesk tightens permissions.

The header also shows a **Monthly Active Leads** usage meter (the MAC metric) and an **Upgrade** link. In this build the Upgrade link lands on Billing, where there is no self-serve upgrade button. It is not a working purchase funnel.

---

## 6. Channels: WhatsApp, Instagram and Messenger

### 6.1 What “connecting” means

SocialDesk uses **one platform-level Meta app** for every workspace. A workspace registers its own Meta asset IDs and access tokens against that app. Inbound webhooks are global; SocialDesk uses the account ID in the payload to decide which workspace owns the message.

You can connect:

| Channel | What the “Account ID” is | Typical token |
| --- | --- | --- |
| **WhatsApp** | The **phone number ID** Meta sends in the webhook (`metadata.phone_number_id`) | Permanent System User access token |
| **Instagram** | The **Instagram business account ID** (`entry.id`) | Page access token with `instagram_manage_messages` |
| **Messenger** | The **Facebook Page ID** (`entry.id`) | Page access token with `pages_messaging` |

### 6.2 Connecting manually

1. Open **Channels**.
2. Click **Connect channel**.
3. Choose WhatsApp, Instagram or Messenger.
4. Fill in:
   - **Display name** (the name your team will see).
   - **Account ID** (the Meta asset ID described above).
   - **Handle** (optional; for example `@yourbrand`).
   - **Business account ID** (optional; for WhatsApp this is the WABA ID).
   - **Access token** (paste it as one line, without `Bearer` or quotes).
   - **Token expiry** (optional; the app shows a warning when it passes).
5. Save. The account is created with status **Connected**.

The token is stored encrypted. If you edit the account and leave the token blank, the existing token is kept.

### 6.3 Testing, statuses and disconnecting

Each account card shows a status:

| Status | Meaning |
| --- | --- |
| **Connected** | The account is enabled. Inbound works even if the token is blank; outbound needs a valid token. |
| **Needs reconnect** | A test failed, or the token is known to be bad. |
| **Disconnected** | Ingestion and outbound are stopped, but history is kept. |

Actions on a card:

- **Test connection** — reads the asset back from Meta using the stored token. A WhatsApp account also shows its quality rating after a successful test.
- **Edit / Fix token** — update the name, handle, business ID, token or expiry.
- **Disconnect / Reconnect** — flips the account between connected and disconnected. Disconnecting preserves all conversations and contact identities.
- **Remove** — only allowed when the account has **no conversation history**. Accounts with history must be disconnected instead, because deleting them would erase conversations.

**A channel with no token is inbound-only.** Inbound webhooks do not need the token, so messages can still arrive; outbound sends will fail.

### 6.4 The Meta webhook panel

The Channels screen shows the platform’s:

- **Callback URL** — the shared platform endpoint. SocialDesk staff configure this in the platform Meta app; do not point it anywhere else.
- **Verify token** — visible to the workspace owner (and SocialDesk super admins). Other roles see “platform-managed”.
- **App secret status** — if `META_APP_SECRET` is not configured, inbound payloads are rejected.
- **Graph API version** — use the same version when copying IDs and tokens.

The webhook is **platform-level**, not per workspace. SocialDesk staff are responsible for the Meta app setup:

1. In the platform Meta app dashboard → **Webhooks**, set the callback URL and verify token, and subscribe to the **messages** field.
2. Confirm the app secret is configured, so signatures verify.
3. Connect each client asset with its ID and access token (section 6.2).

A client does not need to create a separate Meta app for SocialDesk. If inbound messages do not arrive, ask SocialDesk to check the platform webhook before changing anything in the workspace.

### 6.5 WhatsApp Embedded Signup

There is an optional **Connect with Meta** flow intended to let an owner pick a WhatsApp Business Account and phone number without copying IDs. It is:

- visible only to the **workspace owner**;
- **enabled only when SocialDesk has configured `META_APP_ID` and the Embedded Signup configuration ID**;
- not a replacement for the manual form in the current build.

If the green “Connect WhatsApp automatically” panel is absent, use the manual form. Do not promise Embedded Signup to a client until SocialDesk confirms it is enabled for their environment.

### 6.6 Plan limits on channels

- The plan caps **Instagram accounts** (Starter 1, Growth 5, Scale 15). Trying to connect more is blocked with an upgrade message.
- WhatsApp and Messenger accounts are **not capped** by the plan in this build.
- Removing/disconnecting a channel does not remove contacts or conversations.

### 6.7 What works and what does not

| Capability | Status |
| --- | --- |
| Manual connection for all three channels | **Works today** with valid Meta credentials |
| Inbound messages for all three channels | **Works today** when the webhook is configured |
| Free-form outbound inside 24 hours | **Works today** for all three channels |
| WhatsApp template sends | **Works today** through broadcasts and sequences |
| Instagram/Messenger template sends | **Not available** |
| WhatsApp Embedded Signup | **UI only / disabled** until configured by SocialDesk |
| Outbound attachments | **Not available** in the UI |
| Token expiry warning and test connection | **Works today** |

---

## 7. Inbox: the daily workspace

The Inbox is the product home. It has three columns on a wide screen: conversation list, thread, and lead dossier.

### 7.1 The conversation list

- **Search** by lead name, phone or handle. The search filters the **latest 100 conversations already loaded** in the browser; it is not a full-history search.
- **Channel pills**: All, WhatsApp, Instagram, Messenger.
- **Status filter**: All, Open, Resolved.
- The list is ordered by the latest message and shows the contact's stage, up to two tags, the last message preview, time and unread badge.
- The page polls for new data roughly every **15 seconds** while the tab is visible. If Reverb/Echo is configured, new messages also refresh instantly; without it, polling is the only mechanism.

### 7.2 The thread

- An inbound message reopens a resolved conversation automatically and increments its unread count.
- Opening a thread clears its unread badge.
- The thread loads at most the **latest 200 messages**; there is no “load older” control in this build.
- The banner above the thread shows whether the **24-hour window is open**, how long is left, or that it is closed.
- If the window is closed, the banner tells you to send a template — but the composer has no template action. Use a broadcast or sequence.
- **Resolve / Reopen** changes the conversation status. Resolving records who resolved it and when. A new inbound message reopens it.

### 7.3 The composer

| Action | What it does |
| --- | --- |
| **Reply** | Sends a free-form text message to the customer. Enter sends; Shift+Enter makes a new line. |
| **Internal note** | Adds a note only your team can see. It is never sent to the customer. |
| **Quick replies** | Inserts a saved reply from the shared library. |

**Not in the composer:** a template picker, an attachment upload, emoji picker or scheduled send. This is a known gap.

### 7.4 Quick replies

Quick replies are shared by the whole workspace.

- Open the quick-reply menu in the composer, or choose **Manage quick replies**.
- Create a reply with a **title**, **body** and optional **shortcut**.
- Edit or delete replies from the same modal.
- Any member can manage the shared library.

### 7.5 The lead dossier

The right-hand panel shows:

- Contact name, handle/phone and an initials avatar (no photo upload).
- **Assigned to** — usually set by the automatic inbound round-robin router; a routing automation can reassign the first message.
- **Pipeline stage** — a dropdown. Changing it updates the contact immediately.
- **Tags** — an **Add tags / Edit** button opens the tag manager.
  - Tags are shared workspace vocabulary.
  - Create a tag with a name and colour; rename, recolour or delete it.
  - A name is stored without a leading `#`, and must be unique within the workspace.
- **Activity log** — the five most recent automatic events, such as “New lead captured”, “Assigned by round-robin”, “Contact opted out” and “Conversation reopened”. This is not a full audit trail of every edit.
- **Enroll in campaign** — a button that currently shows an informational toast. **It does not enroll the contact.** This is UI only.

### 7.6 Assignment and availability

- Assignment is **automatic and on by default**. When an inbound message creates a new contact, the inbound round-robin router assigns that contact and the new conversation to the available member whose last assignment is oldest — members who have never been assigned come first. This happens before automations run.
- A **routing automation** can then reassign the first-message conversation to a specific person, a pool using round-robin (fewest conversations), or a pool using least-busy (fewest open conversations). It runs only on the first message in a new conversation.
- There is **no manual “assign to” control** in the Inbox and no availability toggle for agents. Every member is stored as available by default.
- Replying to a thread that is still unassigned assigns it to you.
- If a conversation has no assignee and no routing rule matches, it stays unassigned.

### 7.7 Simulating an inbound message

The empty state can show **Simulate inbound**. It creates a test conversation and message in the current workspace so you can demo the Inbox before Meta is connected.

- In production, only the **workspace owner** (or a super admin) sees this.
- In local development, anyone does.
- Simulated rows are real records in that workspace. There is no delete action for contacts or conversations in this build; use a dedicated demo workspace if you do not want them mixed with live data.

### 7.8 Inbox limitations

| Limitation | Detail |
| --- | --- |
| No template sending | The composer is free-form text only. |
| No attachments | No outbound file or image upload. |
| No manual assignment | The automatic inbound round-robin router and optional routing rules assign; agents cannot reassign someone else’s thread. Replying to an unassigned thread claims it. |
| No campaign enrollment | The button is a placeholder toast. |
| Search scope | Filters the latest 100 loaded conversations. |
| No agent availability | Everyone is treated as available for default assignment; routing pools can exclude members. |
| Opt-out is one-way | A contact who replies STOP stays opted out; there is no UI to opt them back in. |

---

## 8. Lead CRM: the contact directory

### 8.1 What the screen shows

- Summary cards: total contacts, opted in, and new this month.
- A searchable, filterable table with contact, source channel, pipeline stage, tags, total DMs, last touch and actions.
- Filters: **search**, **channel**, **stage**, and **consent** (any / opted in / opted out).
- **Open chat** sends you to the Inbox. If the contact’s latest thread is among the 100 conversations currently loaded, it opens that thread; otherwise the Inbox falls back to its default thread. If there is no conversation, it opens the Inbox with the contact’s name as the search term.
- **Export CSV** downloads the rows matching the current filters.

### 8.2 Adding a contact manually

Use **Add Contact** and fill in:

| Field | Required | Notes |
| --- | --- | --- |
| **Name** | Yes | |
| **Phone** | No | Stored as contact data. |
| **Email** | No | |
| **Pipeline stage** | No | Defaults to unassigned. |
| **Opted in to marketing** | Checkbox, default on | Consent for WhatsApp marketing. |

> **Important:** a manually added contact has **no channel identity**. They cannot receive an outbound message until they message one of your connected accounts. They can still appear in an audience and be enrolled in a broadcast, but the send has no recipient identity and will fail. For a clean pilot, prefer contacts captured from real inbound messages.

### 8.3 Editing contacts

The Lead CRM screen is read-only apart from **Add Contact** and **Export CSV**. In this build there is:

- no edit-contact form;
- no delete-contact action;
- no CSV import;
- no bulk tag or bulk stage action;
- no consent/opt-in editor after creation.

Stage and tags are changed from the **Inbox lead dossier**. A contact's opt-in status changes automatically only when they send STOP/UNSUBSCRIBE.

### 8.4 Pipeline stages

Every workspace is created with five fixed stages:

1. New Inquiry
2. Pricing Inquiry
3. Qualified
4. Hot Lead
5. Won / Customer (marked as won)

There is **no UI to add, rename, reorder or delete stages**. Treat them as labels, not a Kanban board; there is no drag-and-drop pipeline view.

### 8.5 Activity log

The dossier shows the contact's activity entries: lead captured, assigned by the router, opted out, conversation reopened. These entries help explain how a lead arrived, but they are not a complete audit log of every field change.

---

## 9. Templates: messages Meta must approve

### 9.1 Why templates exist

A WhatsApp template is a pre-approved message that can be sent outside the 24-hour window. Meta reviews every template. You can create templates in SocialDesk and submit them to Meta, or create them in Meta Business Manager and sync them into SocialDesk.

**Instagram and Messenger do not use templates in this build.**

### 9.2 Template statuses

| Status | What it means |
| --- | --- |
| **In review / Pending** | Submitted to Meta; not usable in a broadcast yet. |
| **Approved** | Meta approved it; it can be used if it is also active locally. |
| **Rejected** | Meta rejected it; the rejection reason is shown. |
| **Paused** | Disabled locally, so it cannot be used even if Meta approved it. |

### 9.3 Creating a template

1. Open **Templates**.
2. Make sure at least one WhatsApp account is connected.
3. Click **Create template**.
4. Fill in:

| Field | Notes |
| --- | --- |
| **Template name** | Lowercase letters, numbers and underscores only. |
| **Category** | Marketing (₹0.88/send), Utility (₹0.30), Authentication (₹0.30). |
| **Language** | For example English (US) or Hindi. |
| **WhatsApp account** | The sending number; the template belongs to it. |
| **Header** | None, static text (up to 60 characters), or an image (JPEG/PNG, up to 5 MB). Text and image cannot both be used. |
| **Body** | Required, up to 1,024 characters. Variables must be sequential: `{{1}}`, `{{2}}`, … |
| **Footer** | Optional small print, up to 60 characters (for example “Reply STOP to unsubscribe”). |
| **Buttons** | Up to 10: quick reply, website URL, or phone call. Dynamic URL variables are not supported. |

5. Click **Submit to Meta**.
6. The template appears with a pending status. Meta reviews it on its own schedule; SocialDesk cannot promise a review time.
7. Use **Sync from Meta** to refresh statuses. When a sync finds an approval or rejection, owners and admins receive an in-app notification.

The builder enforces several Meta rules before submitting:

- the body cannot **start or end with a variable**;
- Meta expects at least **two fixed words per variable plus one** in the body;
- header text, footer text and button labels cannot contain variables;
- at most **two website buttons** and **one phone button** are allowed.

Plan the wording around these rules; the form shows the exact validation message if a rule is broken.

### 9.4 Using a template

An approved, active template can be used in:

- a **WhatsApp broadcast**;
- a **follow-up sequence** step.

It cannot be sent from the Inbox composer.

### 9.5 Pausing and deleting templates

The backend supports pausing (to stop local use without waiting for Meta) and deleting (from Meta and the workspace). **In the current build the Pause and Delete controls are missing for every user because of a known UI bug**, so they cannot be reached from the screen.

Until this is fixed, ask SocialDesk support to pause or delete a template. Do not promise a client that they can pause a template themselves.

### 9.6 Editing and versions

A template cannot be edited in place after submission. To change the wording, create a new template with a different name, submit it, and use the new one once approved. Templates that are already approved remain usable until paused or deleted.

### 9.7 Image headers

- A template can have an image header.
- If the template has a sample image, a broadcast can reuse it.
- If it has no sample image, the broadcast **must** upload one.
- Images are JPEG or PNG, up to 5 MB.
- The campaign preview shows the template body with your variable values.

### 9.8 Template limits and charges

- A template must belong to the **same WhatsApp number** that will send the broadcast.
- Only **approved + active** templates appear in the broadcast picker.
- Template sends debit the wallet at the category rate (₹0.88 marketing, ₹0.30 utility/authentication).
- Free-form replies inside the 24-hour window are not charged.
- If Meta reports a template delivery failure, the wallet charge is refunded once.

---

## 10. Audiences: saved groups of contacts

An audience (the screen is **Audiences**; the underlying route and database call them segments) is a saved filter over contacts. It is used to choose who receives a WhatsApp broadcast.

### 10.1 The base rule

Every audience starts with **opted-in contacts only**. Opted-out contacts are never included, even if they match the other rules.

### 10.2 Available rules

| Rule | Operators | Notes |
| --- | --- | --- |
| **Has tag** | is any of / is none of | Choose one or more workspace tags. |
| **Pipeline stage** | is any of / is none of | Choose one or more stages. |
| **First channel** | is any of | WhatsApp, Instagram, Messenger. |
| **City** | is any of | Matches the contact's city. |
| **Active in last (days)** | within the last N days | Based on the contact's last interaction time. |

Rules are combined with **AND**: a contact must match every rule you add. There is no “any of these rules” OR mode.

> **City caveat:** the City rule only works for contacts whose `city` field already has a value. No screen in this build sets a contact's city — the manual add form has no city field and inbound capture does not fill it. Treat city targeting as unavailable unless SocialDesk has populated the data for you.

### 10.3 Creating and editing an audience

1. Open **Audiences**.
2. Click **New Audience**.
3. Name the audience.
4. Add rules with **Add another rule**.
5. Watch the **Audience size** preview. It is a live count using the same query a broadcast will use.
6. Save.

Saved audiences can be edited or deleted. The list shows a **cached count** captured when the audience was last saved; it is a snapshot, not a live number. The campaign picker displays that saved count, while the audience preview and the actual enrollment use the live query.

### 10.4 Using an audience

In the WhatsApp broadcast form, choose either:

- **All opted-in contacts**, or
- one saved audience.

The broadcast enrolls contacts at send time using the live filter. A contact who opts out before enrollment is skipped. A contact added after the broadcast starts can still be picked up in a later enrollment batch while the campaign is active.

> **Identity caveat:** audience membership is based on contact rows. A manually added contact with no channel identity can match an audience and be enrolled, but the actual send will fail. Keep manually added contacts out of broadcasts until they have messaged you.
>
> **Channel caveat:** “All opted-in contacts” is **not** limited to WhatsApp. A contact known only from Instagram or Messenger has no WhatsApp identity, so their broadcast enrollment fails. For a WhatsApp broadcast, use a saved audience with **First channel = WhatsApp** (or otherwise exclude non-WhatsApp contacts).

---

## 11. Campaigns: broadcasts and social funnels

The Campaigns screen offers three funnel types. Only one of them is fully executed today.

| Funnel | What it is meant to do | Status |
| --- | --- | --- |
| **WhatsApp Cloud: Pre-approved Template Broadcast** | Send an approved template to an opted-in audience. | **Works today** |
| **Instagram: Auto DM on Post Comment keyword** | DM people who comment a keyword on Reels/Feed posts. | **UI only** — campaign row and trigger are created; no worker sends the DM |
| **Facebook Messenger: Ad Click-to-Message Welcome** | Greet people who tap a Click-to-Message ad. | **UI only** — campaign and trigger rows are created; no worker sends the welcome |

> **Do not sell the Instagram or Messenger funnels as working automations yet.** They appear in the list as active campaigns, but nothing is delivered. This is the single most important honesty point in this guide.

### 11.1 Creating a WhatsApp broadcast

1. Confirm you have:
   - an approved, active WhatsApp template;
   - a connected WhatsApp number that owns that template;
   - a positive wallet balance;
   - the platform queue and scheduler running (SocialDesk operates these).
2. Open **Campaigns** → **Create New Campaign**.
3. Choose **WhatsApp Cloud: Pre-approved Template Broadcast**.
4. Enter a **Campaign Title**.
5. Choose an **Approved Template**. The form shows which number will send it.
6. Fill in the template variables. The inputs match the number of `{{n}}` placeholders. Merge tags such as `{{first_name}}`, `{{name}}`, `{{phone}}` and `{{city}}` are available. A merge tag renders blank when the contact field is empty; city is usually blank because no screen sets it (see 10.2).
7. If the template has an image header:
   - upload a campaign image, or
   - reuse the template's sample image when one exists.
8. Choose the audience:
   - **All opted-in contacts** (not WhatsApp-only; see 10.4), or
   - a saved audience.
9. Optionally set a **Send time**. Blank means “send now”. A future time creates a **Scheduled** campaign.
10. Submit.

The app validates the template, the sending number, the audience and the schedule. A past send time is rejected.

> **Time-zone warning:** the current build treats the send time you type as the platform time zone, which is **UTC**, not your browser's local time. In India that is **5 hours 30 minutes behind IST**, so enter **04:30** for a **10:00 IST** send. The confirmation message shows the UTC value; the campaign card converts it to your browser's local time, so verify the card shows the time you intended. SocialDesk staff should confirm the server time zone before relying on scheduled sends.

### 11.2 What happens after launch

- The campaign is **Active** (or **Scheduled**).
- The platform enrolls contacts in batches and queues template sends.
- Each template send debits the wallet.
- Meta delivery status is recorded; the campaign's **Delivered** counter increases as messages are delivered.
- If the wallet runs out, the campaign is **paused** and affected enrollments are marked failed. Topping up does **not** automatically retry failed contacts. Resume the campaign manually for any remaining work.
- A failed template delivery refunds the wallet charge once.
- When there are no more contacts to enroll and no pending sends, the campaign becomes **Completed** and owners/admins get an in-app notification.
- If the audience is **empty** (no opted-in contacts), the campaign stays **Active** instead of completing, so it can pick up contacts who opt in later. Pause it if you do not want that.

### 11.3 Campaign statuses and actions

| Status | Meaning | What you can do |
| --- | --- | --- |
| **Active** | Sending or waiting to send. | Pause |
| **Scheduled** | Waiting for its send time. | Pause (holds it; Resume restores the schedule while the send time is still in the future) |
| **Paused** | Stopped. | Resume (restores the future schedule, or continues now if the send time has passed) |
| **Completed** | Finished. | No action |

There is **no edit, duplicate or delete campaign action** in the UI. Plan the audience and template before launching.

> **Scheduled-campaign wording:** the Pause button's tooltip says “Cancel this scheduled send”, but pausing does **not** delete or complete the campaign. It stays in **Paused**; simply do not resume it if you want to abandon the send.

### 11.4 The counters — read this before reporting results

Each campaign card shows **Enrolled**, **Delivered**, **Responses** and **Leads Won**.

- **Enrolled** and **Delivered** are maintained automatically. **Delivered** depends on Meta’s delivery-status webhook; if the platform webhook is misconfigured, messages can send while Delivered stays at `0`.
- **Responses** and **Leads Won** are **never incremented** by the current delivery jobs. They will stay at `0` even when customers reply or buy.
- The top-of-page **Avg. DM Reply Rate** and **Captured Leads** use those same counters, so they also stay at `0` (or show only historical data if a row was changed manually).
- **Cost per acquisition** divides campaign cost by Leads Won, so it also stays at ₹0.00.
- The **Total Broadcasts Sent** figure is the sum of delivered counts, not the number of campaigns.
- **Do not use the Responses / Leads Won columns as a business report yet.** Measure replies manually in the Inbox; the only CSV export in this build is the Lead CRM contact list.
- There is **no per-recipient delivery list**; the campaign card shows aggregate counters only.

### 11.5 Instagram comment funnel (UI only)

You can create a campaign with:

- a title;
- the funnel type **Instagram: Auto DM on Post Comment keyword**;
- trigger keywords (comma-separated, e.g. `PRICE, LINK, BUY, CODE`);
- a message body.

This funnel does not use the broadcast template, audience or send-time fields.

What actually happens:

- A campaign row is created with type `comment_to_dm`, channel `instagram`, status `active`.
- A trigger row is created for the keyword, scoped to all posts.
- **No service listens for Instagram comments and no DM is sent.** The campaign is a record only.

The same is true of the Messenger **Ad Click-to-Message Welcome** funnel: campaign and trigger rows are created, but no welcome message is sent.

### 11.6 Broadcast prerequisites

A broadcast only sends when all of these are true:

- the template is approved by Meta and active locally;
- the template belongs to the connected WhatsApp number;
- the contact is opted in;
- the contact has a WhatsApp identity from an inbound message;
- the wallet has enough balance;
- the queue worker and scheduler are running;
- the account is not disconnected.

If any condition is missing, the send fails or the campaign pauses. The UI reports the broad reason through status and balance messages; the admin webhook log is the place to investigate delivery failures.

---

## 12. Automations: auto-replies, routing and follow-ups

Automations are rules that run on inbound messages or on a schedule. The screen offers three types.

| Type | Trigger | What it does | Status |
| --- | --- | --- | --- |
| **Auto-reply** | A matching inbound message | Sends a free-form reply and optionally assigns the conversation. | **Works today** |
| **Routing** | The first message in a new conversation | Assigns the conversation to a person or a pool. | **Works today** |
| **Follow-up sequence** | A conversation where the customer has gone quiet after our last message | Sends one or more approved templates after delays. | **Works today** |

### 12.1 Business hours

The Automations screen includes a **Business hours** card:

- **Timezone** (for example `Asia/Kolkata`)
- **Opens** and **Closes** times
- **Open days**

Messages arriving outside this window count as “out of hours” for rules that use that condition. The default is **Monday–Saturday, 10:00–19:00, Asia/Kolkata**. Save the hours before relying on out-of-hours rules.

### 12.2 Auto-reply rules

Create an auto-reply with:

| Setting | Meaning |
| --- | --- |
| **Name** | Internal label. |
| **Status** | Active or Paused. |
| **Priority** | Lower number runs first. |
| **Channels** | WhatsApp / Instagram / Messenger. Leave all off to match every channel. |
| **Only when the workspace is closed** | Applies the business-hours condition. |
| **Keywords** | Comma- or newline-separated, up to 50. Matching is case-insensitive **contains**. An empty list means “any message”. |
| **Only the contact's first message** | Restricts the rule to a welcome message. |
| **Reply** | Free-form text sent to the customer. |
| **Also hand the thread to** | Optionally assign the conversation to a member. |

Execution rules:

- Rules are evaluated by **priority**, then by creation order. **The first matching rule wins** — one reply, not a stack of replies.
- A per-contact, per-rule **60-minute cooldown** prevents the same rule from answering the same contact repeatedly.
- Auto-replies are skipped for opted-out contacts.
- Auto-replies send **free-form text**. They normally run immediately after an inbound message, so the 24-hour window is open. If the send fails (for example, the channel is disconnected), the rule is skipped and the inbound message is still stored.
- Auto-replies do **not** clear the unread badge or set the first-response time. An auto-reply assigns the thread only when the rule's **Also hand the thread to** action is set; otherwise the existing assignment is unchanged.

### 12.3 Routing rules

A routing rule applies only to the **first message in a new conversation**. Even without a rule, the inbound round-robin router already assigns each new contact to a member; a routing rule is an **override** that can reassign the first-message conversation using one of these strategies:

| Strategy | Behaviour |
| --- | --- |
| **Round-robin** | The available agent with the fewest conversations (ties broken by who was assigned longest ago). |
| **Least busy** | The available agent with the fewest **open** conversations. |
| **One specific person** | Always the chosen member. |

You can also restrict the pool to selected members. Conditions include channel and “outside business hours”.

> **Availability caveat:** there is no UI to mark a member unavailable. Everyone is treated as available for the default inbound round-robin. Within a routing rule you can remove an agent from the pool or pause the rule, but the default assignment can still choose them.

The router writes an activity entry and assigns both the conversation and the contact. The Inbox shows “assigned to …” in the thread header. There is no manual reassignment control.

### 12.4 Follow-up sequences

A sequence chases conversations where the customer has gone quiet **after our last message**.

Settings:

| Setting | Meaning |
| --- | --- |
| **Start when the contact has been silent for** | Number of hours after our last message. |
| **Channels** | Which channels the sequence applies to. |
| **Steps** | Each step has a delay in minutes after the previous step, an approved template, and optional variable values. |

A sequence:

- only enrolls conversations where at least one outbound message exists;
- uses **approved templates** only;
- debits the wallet per template send;
- fills blank variables with the contact's first name so Meta never receives an empty placeholder;
- **cancels** a contact's run when they reply, opt out, the template disappears or is no longer approved, the channel is disconnected, or the wallet has insufficient balance;
- shows run counts and last-run time on the automation card, but not a per-contact run history.

Sequences depend on the platform scheduler and queue. In production, SocialDesk staff run these; if a sequence is not firing, contact support rather than re-creating it repeatedly.

### 12.5 Managing automations

- Create with **New automation**.
- Edit, pause/resume, or delete an existing rule.
- Priority controls first-match order.
- The card shows the rule type, status, trigger, target, template and counters.

### 12.6 Automation limitations

| Limitation | Detail |
| --- | --- |
| No manual assignment | Automatic routing only; agents cannot reassign someone else’s thread. Replying to an unassigned thread claims it. |
| No availability toggle | All members count as available for default assignment; routing pools can exclude them. |
| No free-form sequence steps | Sequences send templates only. |
| No per-contact sequence log | Only aggregate counters are shown. |
| No Instagram comment execution | That belongs to the campaign funnel that is not wired. |
| Auto-reply is text only | No template, attachment or rich message. |

---

## 13. Analytics

Analytics is a read-only summary over the selected window: **7, 30 or 90 days** (default 30).

### 13.1 The cards

| Card | Definition | Caveat |
| --- | --- | --- |
| **Messages Out** | Outbound messages in the window, with the count that came from broadcasts. | Includes auto-replies, agent replies and template sends. |
| **Messages In** | Inbound messages in the window. | The sub-label shows a “reply ratio” calculated as inbound ÷ outbound. It is **not** a true per-conversation reply rate. |
| **First Response** | Average time to the first agent response, for conversations created in the window. | Auto-replies do not count as the first human response; the average uses at most 500 conversations. |
| **Wallet Spend** | Sum of template-message debits in the window. | Does not include plan fees or top-ups. |

### 13.2 The charts

- **Message Volume** — daily inbound and outbound bars.
- **Audience** — new contacts and conversations opened in the window; current still-open conversations and opted-in contacts.
- **Channel Mix** — conversations created in the window by channel.
- **Top Broadcasts** — the five broadcasts with the highest delivered count, showing enrolled, delivered, replied and won.

### 13.3 Important interpretation notes

- The **“reply ratio”** is inbound messages divided by outbound messages. A high number does not necessarily mean customers are answering a particular campaign.
- **Top Broadcasts** reuses the campaign counters. Because **Replied** and **Won** are not incremented by the delivery worker, those columns will read `0`. Use them as delivery summaries, not conversion reports. The list is **all-time**, not limited to the selected window.
- Everything is computed from live message, conversation, contact and wallet rows. There is no separate reporting warehouse and no real-time refresh; reload the page for current numbers.
- Range boundaries and daily chart buckets use the platform time zone (**UTC**). For an IST team, each chart “day” starts at **05:30 IST**, so messages near midnight can fall into the previous or next bar.

---

## 14. Wallet, plans and billing

### 14.1 What Billing shows

- **Plan**: name, price, GST percentage, billing interval.
- **Subscription**: status (`trialing`, `active`, `past_due`, `cancelled`), trial end, current period.
- **Usage**: monthly active contacts used vs. the plan limit, a percentage bar, and an overage estimate.
- **Add-ons**: Instagram accounts used/max, agent seats used/max, total channels.
- **Wallet**: balance, estimated messages remaining, and the per-message rate shown as the marketing rate (₹0.88).
- **Transactions**: the last 15 wallet ledger lines.
- **Invoices & Payments**: the last 10 payments, with invoice number, purpose, amount, GST, method and status.
- **Add Balance** button.

### 14.2 Monthly active contacts (MAC)

A contact counts once per billing period when they send an **inbound** message. The same contact does not count twice in the same period.

- The plan's MAC limit is a **reporting/quota number**, not a hard block: inbound messages are still accepted when you are over.
- Billing shows how many overage blocks (500 contacts each) you are over and multiplies by the plan's overage price.
- **The overage amount is displayed as an estimate but is not charged automatically in this build.** No overage invoice is generated by the app. Agree any overage billing with SocialDesk manually.

### 14.3 Wallet economics

| Event | Wallet effect |
| --- | --- |
| Template message sent | Debit at the template category rate: marketing ₹0.88, utility/authentication ₹0.30. |
| Free-form reply inside 24 hours | No charge. |
| Template delivery fails and Meta reports failure | Refund once for that message. |
| Top-up | Credit the amount you enter. |
| Admin goodwill credit | Credit, recorded as an adjustment. |

When a debit crosses below **₹50**, owners and admins get a one-off “wallet running low” in-app notification. The warning fires on the crossing, not on every send.

### 14.4 Topping up — the critical warning

The **Add Balance** modal offers presets of **₹1,000, ₹2,500 and ₹5,000**, plus a custom amount from **₹100 to ₹5,00,000**. It adds **18% GST** on top of the entered amount, records a payment and invoice, and credits the wallet with the entered amount.

**In the current build, if Razorpay is not configured, the top-up is a manual test capture:**

- no money is collected;
- the payment is marked `captured` with method `upi`;
- fake order and payment IDs are stored;
- the wallet is credited immediately.

The modal itself says: *“Razorpay isn't connected in this environment, so this credits the wallet instantly for testing.”*

Once SocialDesk configures a Razorpay key, this manual route **disables itself** and returns an error until the real checkout is wired. Do not use the current top-up with real money or with untrusted users.

> **Do not run a live paid pilot on this build.** Any member who can open Billing can record a top-up that credits the wallet without a real payment. SocialDesk must either configure the gateway or manage wallet credits from the admin console.

### 14.5 Invoices

Each manual top-up creates an invoice number in the form `INV-YYYY-NNNNN`, with the amount, 18% GST, method `upi`, status `captured` and a paid timestamp. These are records in the app, not a substitute for a real tax invoice from a payment gateway.

### 14.6 Changing plans

There is **no self-serve upgrade, downgrade or cancel** in the tenant UI or the admin console. The header's **Upgrade** link goes to Billing, where you can view the plan and top up the wallet, but not switch plans.

What SocialDesk staff can do from the admin console:

- extend a trial or paid period;
- override MAC, seat or Instagram limits;
- credit the wallet;
- suspend or reactivate a workspace.

They cannot change the workspace's plan from the admin screen in this build. Plan changes require provisioning outside the console (or a future feature).

### 14.7 Access after the trial

When a trial expires, product routes redirect to Billing. There is no purchase button on Billing. A SocialDesk admin must extend the trial/period, and a paid subscription must be provisioned outside the tenant UI. Plan the commercial hand-off before day 14.

---

## 15. Team, roles and invitations

### 15.1 Roles

| Role | Intended scope |
| --- | --- |
| **Owner** | The workspace creator. Full access; cannot be removed or demoted. |
| **Admin** | Can manage agents and most workspace operations; cannot manage the owner or other admins. |
| **Agent** | Day-to-day inbox work. |

### 15.2 What is actually role-gated

This build has fewer restrictions than the role names suggest:

| Area | Actual access |
| --- | --- |
| Inbox, Lead CRM, Campaigns, Audiences, Automations, Analytics | All members |
| Channels | All members; only owner/super admin sees the webhook verify token |
| Billing and wallet top-up | All members |
| Team management | Owner and admin; admin can manage agents only |
| Template creation / pause / delete | Owner and admin (the pause/delete controls are currently hidden by a UI bug; see 9.5) |
| Template sync | All members |
| WhatsApp Embedded Signup | Owner only |
| Simulate inbound | Owner in production; anyone in local development |
| Admin console | SocialDesk super admins |

Do not treat agent accounts as financially or operationally restricted until this changes.

### 15.3 Seats

The plan includes a number of **agent seats** (Starter 2, Growth 5, Scale 20). Seats used = current members + pending, unexpired invitations. Inviting beyond the seat cap is blocked with an upgrade message.

### 15.4 Inviting a teammate

1. Open **Team**.
2. Enter the teammate's **email**.
3. Choose their **role** (owner can invite admin or agent; admin can invite agent only).
4. Send the invitation.

What happens:

- An invitation valid for **7 days** is created.
- The teammate receives an email with an acceptance link.
- The inviter also sees the **plain invite link once** after sending. If email delivery is not configured, copy that link and share it directly.
- The invite appears under pending invitations with its expiry.
- The inviter can **resend** (refreshes the link and expiry) or **revoke** it.
- Accepting the invitation adds the user to the workspace, notifies the owner, and makes that workspace the user's current workspace.

### 15.5 Changing or removing members

- Owners can change a member's role between admin and agent, and can remove admins or agents.
- Admins can manage agents but not other admins or the owner.
- The owner cannot be removed or demoted.
- A member cannot change their own role.
- Removing a member does not delete their user account; it removes their access to that workspace.

### 15.6 Availability

There is no availability toggle in Team or Inbox. Routing rules consider every member available, and the **default inbound assignment can still choose an agent even when they are excluded from a rule's pool**. The UI offers no way to keep a member out of all default assignment; ask SocialDesk support if that is essential.

---

## 16. Notifications

The bell in the header opens the **Notifications** screen. It shows the signed-in user's own notifications for the current workspace, newest first, up to 100.

- **Mark all read** clears the badge.
- Clicking a notification marks it read and follows its link.
- Notifications are **in-app only**; there are no email, SMS or push alerts for these operational events.

Events that create in-app notifications:

| Event | Who receives it |
| --- | --- |
| Wallet balance crosses below ₹50 | Owners and admins |
| Template approved or rejected during a sync | Owners and admins |
| Broadcast completed | Owners and admins |
| A teammate accepts an invitation | Owner |

Email is used for workspace invitations and password resets, not for these operational notifications.

---

## 17. Profile and account settings

The account menu opens **Profile**, a separate account page with:

- **Profile information**: name and email.
- **Update password**.
- **Delete account**.

Deleting an account is a destructive action. If the user owns a workspace, make sure ownership and access are handed over first; the app does not provide a guided ownership-transfer flow.

The account menu also provides:

- **Switch workspace** — shown when you belong to more than one workspace; move between them.
- **Create workspace** — start a new trial workspace.
- **Log out**.

There is **no workspace settings screen** in this build: you cannot rename, delete, change the logo of, or change the default timezone of a workspace from the tenant UI. Business-hours timezone is edited on the Automations screen.

---

## 18. Admin console (SocialDesk staff only)

The admin console lives under `/admin` and is available only to accounts flagged as **super admin**. It is not part of the client workspace navigation.

### 18.1 Platform overview

- Workspace counts: total, active, suspended.
- User counts: total users, staff.
- Subscription counts: active, trialing, past due, cancelled.
- MRR estimate from active plan prices.
- Total contacts and conversations, plus messages in the last 30 days.
- Total wallet balance and the number of low-balance wallets.
- Webhook counts: pending and failed (all-time), and processed in the last 24 hours.
- Recent workspaces and recent webhook events.

### 18.2 Workspaces

- Search by workspace name, slug or owner name/email.
- Filter by active/suspended.
- Open a workspace to see:
  - owner, status, timezone, currency and creation date;
  - subscription status, trial end and period end;
  - plan and usage;
  - counts of contacts, conversations, open conversations, messages, campaigns, templates and automations;
  - wallet balance and recent ledger;
  - recent payments;
  - members;
  - recent webhook events.
- Actions:
  - **Suspend** with an optional reason; **activate** again.
  - **Extend trial/period** by 1–365 days. A lapsed trial is extended from today.
  - **Override limits** for MAC, agent seats and Instagram accounts. Blank fields fall back to the plan.
  - **Credit the wallet** with an amount and description.

The admin cannot change the workspace's plan in this screen.

### 18.3 Plans

- List every plan, its price, quotas and active state, plus subscriber counts.
- Edit **name, price, MAC limit, agent seats, Instagram limit and active state**.
- Editing a plan is visible to tenants immediately on Billing and the public pricing page.

The plan editor does not expose overage pricing, block size or GST in this build.

### 18.4 Webhook events

- Paginated log of raw inbound events, filterable by status (pending, processed, failed, ignored), provider and search term.
- Shows event type, external ID, channel account, error, payload and timestamps.
- **Retry** is available for Meta events that are not yet processed. Other providers are not retryable here.

This is the first place to look when a client says “a customer messaged and nothing appeared”.

---

## 19. What works today vs. what is only a screen

Use this matrix in a demo, a proposal or a pilot scope. It is the short version of the whole guide.

| Area | Status | What actually happens |
| --- | --- | --- |
| Public landing page and pricing | **Works today** | Plans are read from the live plan records. |
| Registration and 14-day trial | **Works today** | Workspace, owner, Growth trial, wallet and five stages are created. Email verification is not enforced. |
| Login, logout, password reset | **Works today** | Password reset depends on email delivery being configured. |
| Create/switch workspace | **Works today** | Each new workspace starts its own trial and wallet. |
| Team invitations and roles | **Works today** | 7-day invite link; email depends on mailer; owner/admin/agent rules as described. |
| Manual channel connection | **Works today** | Requires valid Meta IDs and tokens. |
| WhatsApp Embedded Signup | **UI only / disabled** | Owner-only; needs platform Meta app configuration. Manual form is the fallback. |
| Meta webhook inbound | **Works today** | Requires callback URL, verify token and app secret. |
| Inbox free-form reply | **Works today** | Inside the 24-hour window only. |
| Inbox template send | **Not available** | No template action in the composer. |
| Inbox attachments | **Not available** | Text and internal notes only. |
| Inbox internal notes | **Works today** | Visible to the team only. |
| Inbox quick replies | **Works today** | Shared library; any member can manage. |
| Inbox tags and stage | **Works today** | Edited from the dossier. |
| Inbox resolve/reopen | **Works today** | New inbound reopens a resolved thread. |
| Inbox long history | **Partial** | Only the latest 200 messages load; there is no load-older control. |
| Inbox manual assignment | **Not available** | Automatic round-robin and routing rules; replying claims an unassigned thread. |
| Inbox campaign enrollment | **UI only** | Button shows a toast; no enrollment. |
| Lead CRM add, filter, export | **Works today** | Manual contacts have no channel identity. |
| Lead CRM edit/delete/import | **Not available** | — |
| Pipeline stage management | **Not available** | Five fixed stages. |
| Template create and submit to Meta | **Works today** | Owner/admin; Meta approval required. |
| Template sync and notifications | **Works today** | Sync refreshes statuses; approval/rejection notifications on sync. |
| Template pause/delete in UI | **UI only (hidden)** | Known UI bug: backend exists; controls are missing. Ask support. |
| Audiences (saved filters) | **Works today** | Opted-in only; AND rules; live count for campaigns. City filter needs data that no UI currently sets. |
| WhatsApp broadcast | **Works today** | Approved template, connected number, wallet, queue/scheduler. |
| Broadcast scheduling | **Partial** | Scheduler works, but the typed time is treated as **UTC**, not local time (IST = UTC+5:30). |
| Broadcast delivery counters | **Partial** | Delivered works; Responses and Leads Won do not increment. |
| Per-recipient delivery report | **Not available** | Campaign cards show aggregate counters only. |
| Instagram comment campaign | **UI only** | Row and trigger created; no execution. |
| Messenger ad-click campaign | **UI only** | Row and trigger created; no execution. |
| Auto-reply automation | **Works today** | First match wins; 60-minute cooldown; free-form. |
| Routing automation | **Works today** | First message only; round-robin / least-busy / specific person. |
| Follow-up sequences | **Works today** | Approved templates, delays, wallet; cancels on reply/opt-out/errors. |
| Business hours | **Works today** | Used by out-of-hours rules. |
| Agent availability toggle | **Not available** | Everyone is available for default assignment; routing pools can exclude. |
| Analytics | **Works today** | Read-only; see metric caveats. |
| Campaign reply/won analytics | **Partial / zero** | Counters are not wired. |
| Wallet debit and refund | **Works today** | Template sends debit; failed delivery refunds once. |
| Wallet top-up | **Partial / test only** | Manual capture without a gateway; do not use real money. |
| Plan upgrade/downgrade | **Not available** | Upgrade link dead-ends at Billing. |
| Overage billing | **UI only** | Displayed as an estimate; not charged. |
| Seat and Instagram caps | **Works today** | Enforced on invite/connect. |
| WhatsApp/Messenger channel caps | **Not enforced** | Plan only caps Instagram. |
| Access after trial expiry | **Partial** | Redirects to Billing; no purchase flow. |
| In-app notifications | **Works today** | Per-user, last 100, in-app only. |
| Profile and password | **Works today** | Separate account page. |
| Workspace settings/rename/delete | **Not available** | — |
| Opt-out (STOP/UNSUBSCRIBE) | **Works today** | Whole message must equal STOP/UNSUBSCRIBE; no re-opt-in UI. |
| Admin console | **Works today** | Staff-only; suspend, extend, override limits, credit wallet, plans, webhook retry. |
| Admin plan switch | **Not available** | Admin can extend/override, not change the plan. |
| Real-time Inbox | **Partial** | 15-second polling; instant only if Reverb is configured. |
| Dashboard overview | **UI only** | Starter placeholder; not linked or used. |

---

## 20. Operating checklist

### Before a client goes live

- [ ] Confirm the client's Meta business assets: the WhatsApp number, Instagram account and/or Facebook Page, and that they are assigned to the platform Meta app.
- [ ] Confirm with SocialDesk that the shared Meta webhook is configured: callback URL, verify token, app secret and the **messages** subscription.
- [ ] Connect the channels with the correct IDs and tokens; run **Test connection** on each.
- [ ] Create or sync at least one approved WhatsApp template.
- [ ] Set the wallet balance through a controlled method: admin credit in this build, or a live gateway only after SocialDesk wires it.
- [ ] Invite the team with the right roles.
- [ ] Set business hours and timezone if using out-of-hours rules.
- [ ] Send a real test message from a personal phone and confirm it appears in the Inbox.
- [ ] Reply from the Inbox and confirm the customer receives it.
- [ ] Create a tiny test audience and a test broadcast; confirm delivery, counter and wallet debit.
- [ ] If you will schedule sends, remember the time field is **UTC** (IST minus 5:30); confirm the saved schedule in the campaign card.
- [ ] Confirm who will monitor the wallet and the escalation contact for failed sends.
- [ ] Agree what happens when the trial ends; there is no self-serve purchase.

### Daily

- [ ] Open the Inbox; clear unread and unresolved threads.
- [ ] Check the 24-hour banners and use a broadcast or follow-up sequence where the window is closed.
- [ ] Add tags and move stages as conversations progress.
- [ ] Watch the wallet meter and the notification bell.
- [ ] Check that no channel shows **Needs reconnect**.

### Weekly

- [ ] Review Analytics for message volume and first-response time.
- [ ] Review active broadcasts and sequences; pause anything misconfigured.
- [ ] Sync templates and check for rejections.
- [ ] Review pending invitations and seat usage.
- [ ] Export contacts if the client needs a CRM backup.

### Monthly

- [ ] Review MAC usage and any displayed overage estimate with SocialDesk.
- [ ] Reconcile wallet transactions and invoices.
- [ ] Review team roles and remove departed staff.
- [ ] Review automations for stale templates or keywords.
- [ ] Confirm the client understands the counters that are not yet automatic.

---

## 21. Troubleshooting

| Symptom | Likely cause | What to do |
| --- | --- | --- |
| No inbound messages appear | Webhook not configured, wrong callback/verify token, missing app secret, or the Meta asset is not subscribed | Check the Channels webhook panel; ask SocialDesk to inspect the admin webhook log. |
| Channel says **Needs reconnect** | Token expired, revoked, or copied with whitespace/quotes | Edit the channel, paste a fresh token, save, then test. |
| Reply fails in the Inbox | 24-hour window closed, channel disconnected, or no token | Use a template broadcast/sequence for outside-window contact; fix the channel. |
| “24-hour window closed” but the client needs to reply now | Inbox has no template picker | Use a WhatsApp broadcast to that contact's audience or a follow-up sequence. |
| Broadcast stays Active with no deliveries | Queue/scheduler not running, wallet empty, template not approved/active, template belongs to another number, contact has no identity, or the delivery-status webhook is missing | Check campaign status, wallet, template status and channel; ask SocialDesk to check the queue and webhook log. |
| Broadcast paused unexpectedly | The wallet ran out; the app pauses the campaign. Other delivery failures mark individual contacts failed without pausing the campaign. | Top up and resume for any remaining contacts. The contact that hit the empty wallet is already marked failed and is **not** retried automatically. |
| Responses / Leads Won stay at 0 | Known gap: counters are not incremented | Do not use them as a conversion report; measure replies manually. |
| Instagram or Messenger campaign sends nothing | Known gap: these funnels are not executed | Do not promise them; use a working WhatsApp flow for the pilot. |
| Template Pause/Delete buttons are missing | Known UI bug | Ask SocialDesk support to pause/delete the template. |
| Invite email did not arrive | Mailer not configured or message in spam | Copy the invite link shown after sending, or resend. The link expires in 7 days. |
| Password reset email did not arrive | Mailer not configured | Ask SocialDesk to check mail configuration. |
| Trial ended and the client is stuck on Billing | No self-serve purchase | Ask a SocialDesk admin to extend the trial/period; a paid plan must be provisioned outside the tenant UI. |
| Top-up shows an error | A Razorpay key has been configured, so the manual test route is disabled | Use the real gateway once checkout is wired, or ask an admin to credit the wallet. |
| Wallet was credited without payment | Manual test capture is active | Expected in a non-gateway environment; do not run a paid pilot this way. |
| Contact received STOP and is now excluded | Opt-out is intentional and one-way | Respect the opt-out. There is no self-serve re-opt-in; ask support only if the contact explicitly re-consents. |
| Contact added manually never receives a broadcast | No channel identity | Wait for them to message you; there is no way to attach a channel identity manually. |
| Search cannot find an old conversation | Inbox search covers only the latest 100 loaded conversations | Narrow by channel/status to bring older threads into range, or use Lead CRM to find the contact. A thread outside the loaded 100 may not open from Lead CRM. |
| Agent cannot be marked unavailable | No availability UI, and the default inbound round-robin still assigns to them | Remove them from routing pools for rule-based assignment, but new inbound contacts may still be auto-assigned. Ask SocialDesk support for full exclusion. |
| Need to reassign a conversation | No manual assignment UI; routing rules only act on the first message | Reply to claim an unassigned thread, or ask SocialDesk support. Routing rules do not retroactively move an existing thread. |

---

## 22. Glossary

| Term | Meaning |
| --- | --- |
| **Workspace** | A client tenant. Owns channels, contacts, conversations, campaigns, wallet and plan. |
| **Channel account** | One connected WhatsApp number, Instagram account or Facebook Page. |
| **Contact** | A person in the CRM, usually created from an inbound message. |
| **Conversation / thread** | The message history between one contact and one channel account. |
| **24-hour window** | Meta's rule allowing free-form replies within 24 hours of the customer's last message. |
| **Template** | A pre-approved WhatsApp message usable outside the window. |
| **Audience / segment** | A saved filter over opted-in contacts, used for broadcasts. |
| **Broadcast** | A WhatsApp template send to an audience. |
| **Campaign** | A broadcast or social funnel record in the Campaigns screen. |
| **Automation** | A rule that replies, routes or follows up automatically. |
| **Sequence** | A time-delayed series of template steps. |
| **MAC** | Monthly active contacts: unique contacts who messaged inbound in the billing period. |
| **Wallet** | Prepaid balance that pays for template messages. |
| **Overage** | Contacts over the plan's MAC limit, displayed as an estimated block charge. |
| **Super admin** | SocialDesk staff account with access to `/admin`. |
| **Trial** | The 14-day Growth-plan access created with a new workspace. |

---

*This guide describes the build verified in October 2026. If a client needs one of the items marked “not available” or “UI only”, treat it as a product request for SocialDesk, not as a configuration task for the client.*
