# djebel-simple-newsletter Plugin

## What This Plugin Does

Lightweight newsletter subscription with double opt-in (email + verification code). Collects email addresses, sends a verification code, and stores confirmed subscribers in date-organized CSV files.

**Dependency:** Requires `djebel-mailer` plugin for sending verification emails.

## Shortcode

```html
[djebel-simple-newsletter]
```

### Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `title` | string | `""` | Heading displayed above the form |
| `cta_text` | string | `""` | Call-to-action text before the form |
| `render_agree` | boolean | `0` | Show GDPR consent checkbox |
| `auto_focus` | boolean | `0` | Auto-focus the email input field |
| `agree_text` | string | `"I agree to be notified"` | Custom GDPR checkbox text |

### Usage Examples

```html
[djebel-simple-newsletter cta_text="Subscribe to our newsletter" render_agree=1 auto_focus=0]

[djebel-simple-newsletter title="Stay Updated" cta_text="Get notified about new releases"]
```

## How It Works

### Step 1: Email Submission (AJAX POST, action=`join`)
1. Shortcode renders the form (email input + honeypot fields + optional GDPR checkbox)
2. User enters email and submits
3. JS sends AJAX POST to current page with `simple_newsletter_action=join`
4. Server-side `ajaxJoin()`:
   - Honeypot check (fake success if bot detected)
   - Email validation (`filter_var` + `FILTER_VALIDATE_EMAIL`)
   - GDPR consent check (if `render_agree=1`)
   - Custom validation hook: `app.plugin.simple_newsletter.validate_data`
   - Generates deterministic auth code via `Dj_App_String_Util::generateAuthCode()` (based on email + salt + action)
   - Saves email to `unconfirmed.csv`
   - Sends verification email via `djebel-mailer` plugin (code expires in 24 hours)
5. JS hides email form, shows code form, focuses code input

### Step 2: Code Verification (AJAX POST, action=`verify`)
1. User enters the code from their email
2. JS sends AJAX POST with `simple_newsletter_action=verify`
3. Server-side `ajaxVerify()`:
   - Honeypot check
   - Fires `app.plugin.simple_newsletter.before_verify` action
   - Verifies code via `Dj_App_String_Util::verifyAuthCode()`
   - On success: builds subscriber data, applies `app.plugin.simple_newsletter.data` filter, writes to confirmed CSV
   - Fires `app.plugin.simple_newsletter.after_verify` action
4. JS hides code form, shows success message

### Spam Protection
- Two honeypot fields (hidden from real users, caught by bots)
- Bots get fake success response (prevents email enumeration)
- Generic error messages prevent email enumeration for real users too

## Data Storage

**Confirmed subscribers:**
```
dj-app/data/plugins/djebel-simple-newsletter/{YYYY}/{MM}/data_{YYYY}-{MM}-{DD}.csv
```

**Unconfirmed (pending verification):**
```
dj-app/data/plugins/djebel-simple-newsletter/unconfirmed.csv
```

**CSV columns:** `email`, `creation_date` (RFC 2822), `user_agent`, `ip`

## Configuration

No app.ini config. All customization is via shortcode parameters or hooks.

## Hooks

### Actions
| Hook | When |
|------|------|
| `app.plugin.simple_newsletter.validate_data` | During email submission (throw exception to reject) |
| `app.plugin.simple_newsletter.form_start` | Beginning of rendered form (add custom fields) |
| `app.plugin.simple_newsletter.form_end` | End of rendered form (add custom fields) |
| `app.plugin.simple_newsletter.before_verify` | Before code verification |
| `app.plugin.simple_newsletter.after_verify` | After successful verification |

### Filters
| Filter | What It Modifies |
|--------|-----------------|
| `app.plugin.simple_newsletter.data` | Subscriber data before CSV save |
| `app.plugin.simple_newsletter.file` | Confirmed CSV file path |
| `app.plugin.simple_newsletter.set_file` | File path during init |
| `app.plugin.simple_newsletter.unconfirmed_file` | Unconfirmed CSV file path |
| `app.plugin.simple_newsletter.verification_email` | Email content (subject, body, code) |

## CSS Classes

| Class | Element |
|-------|---------|
| `.djebel-simple-newsletter-wrapper` | Main container |
| `.djebel-simple-newsletter-title` | Title heading |
| `.djebel-simple-newsletter-msg` | Message display area |
| `.djebel-snl-email-step` | Email form (Step 1) |
| `.djebel-snl-code-step` | Code verification form (Step 2) |
| `.newsletter-input-group` | Input + button container |
| `.newsletter-agree-section` | GDPR checkbox section |
