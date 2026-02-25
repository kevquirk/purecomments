# Pure Comments

A lightweight PHP (8+) comments service for static sites and blogs.

It provides:
- An embeddable frontend widget (`/public/embed.js`)
- A JSON API for fetching/submitting comments (`/api/...`)
- A root admin panel (`/`) with moderation and author replies
- First-run setup (`/setup.php`) that creates `config.php` and the SQLite DB

## Current Architecture

- `index.php` is the admin UI (root path `/`)
- `login.php` handles admin sign-in
- `settings.php` handles admin settings updates
- `logout.php` ends the admin session
- `setup.php` is first-run installer (only when `config.php` is missing - will delete after first run)
- `api/index.php` is the API front controller
- `public/embed.js` is the embed script used on your blog/site
- `includes/` contains auth, DB, rendering, SES, session hardening, and helpers

## Features

- SQLite-backed comments with threaded replies
- Markdown input with safe HTML rendering (Parsedown safe mode)
- Optional commenter email encryption using libsodium
- Optional commenter website links
- Spam checks on submission (honeypot + human challenge)
- Configurable human challenge question/answer (set in Setup/Settings)
- Admin moderation for pending/published comments
- Author replies from admin panel
- Auto-publish pending comment when replying as author
- Thread delete from root comment (`Delete thread` removes descendants)
- Admin pagination (20 primary threads per page)
- Login rate limiting (5 failed attempts in 5 minutes)
- Session hardening (`HttpOnly`, `SameSite`, strict mode, secure-on-HTTPS)

## Requirements

- PHP 8+
- PHP extensions:
  - `pdo_sqlite`
  - `sodium`
  - `curl` (for SES email sending)
- Apache or nginx
- Write access for PHP user to:
  - project root (during setup, to create `config.php`)
  - `db/` directory (for SQLite and login rate-limit store)

## Installation

1. Deploy `purecomments` to your comments domain/subdomain.
2. Ensure filesystem permissions allow creating `config.php` and writing `db/`.
3. Visit `/setup.php` and complete setup.
4. Sign in at `/login.php`.
5. Moderate comments at `/`.

### Important

- Run setup immediately after deployment.
- Complete setup before exposing the service publicly.
- After setup completes, `setup.php` is automatically deleted.

## Config Notes

`setup.php` writes `config.php` with:
- `db_path` using a portable relative expression:
  - `__DIR__ . '/db/comments.sqlite'`
- `admin_username`
- `admin_password_hash`
- `sodium_key`
- `privacy_policy_url`
- `post_base_url`
- `author` details
- `aws` SES details
- `moderation` details

`config.php` is intentionally blocked via root `.htaccess`.

## Auth & Security

- Admin auth is session-based (not HTTP Basic auth).
- Login requires username/password from `config.php`.
- Passwords are verified against `admin_password_hash`.
- Login attempts are rate-limited by username+IP.
- Root `.htaccess` supports IP allowlisting for `index.php` and `login.php` - just uncomment lines 09-12 and change the example IP addresses.

## API Endpoints

Base path: `/api`

- `GET /api/comments/{post_slug}`
  - Returns published comments for a post as a tree
- `POST /api/submit-comment`
  - Accepts JSON body with fields like:
    - `post_slug`, `name`, `email`, `website`, `content`, `parent_id`, `surname`, `trap_field`
  - Stores comment as `pending`
  - Sends moderation email (if SES configured)

If `config.php` is missing, API returns `503` with a setup hint.

## Embedding On Your Site

Add this where you want comments:

```html
<div id="comments"></div>
<script src="https://comments.example.com/public/embed.js" defer></script>
```

### About `data-post-slug` (optional)

`embed.js` needs a post identifier so it knows which comment thread to load and where new comments should be submitted.
By default, it infers that slug from the current page URL.

You can optionally use `data-post-slug` when URL-based detection is not what you want.

Common cases:
- Your post URL may change over time (slug edits, permalink migrations, trailing slash changes).
- You want two different URLs to share one comment thread (canonical + alternate route).
- You use query-string or hash-based routing and want a clean, stable identifier per post.

```html
<div id="comments" data-post-slug="my-first-post"></div>
<script src="https://comments.example.com/public/embed.js" defer></script>
```

Use one stable value per post. Different slugs create different, isolated threads.

### About `data-base-url` (optional)

`embed.js` normally infers the API base URL from its own `src`. So if your script URL is:

`https://comments.example.com/public/embed.js`

it will call:

- `https://comments.example.com/api/comments/{slug}`
- `https://comments.example.com/api/submit-comment`

You can optionally use `data-base-url` when automatic detection is not what you want.

Common cases:
- You serve `embed.js` via a CDN, but your API is on a different origin.
- You proxy static assets and API through different domains.
- You want to force a specific API origin for testing.

```html
<script src="https://comments.cdn.example.com/public/embed.js" data-base-url="https://comments.example.com" defer></script>
```

### Formatting comments

Pure Comments includes a frontend example stylesheet at `public/comments.css`.

You have two simple options:
- Copy the rules you want into your site stylesheet.
- Link it directly on pages where comments are shown.

Example:

```html
<link rel="stylesheet" href="https://comments.example.com/public/comments.css">
```

`embed.js` only renders markup and behavior, so the visual style is fully up to you. Start with the example file, then adjust spacing, colours, typography, and badges to match your site.

## Admin Behavior

- Comments are grouped into:
  - Pending comments
  - Published comments
- Each section paginates independently.
- Admin view is thread-oriented with accordion `details`.
- Replies display in chronological order within a thread.
- Thread root uses `Delete thread`; replies use `Delete reply`.
- Replying to a pending comment publishes it and posts your reply together.

## Email Behavior

Via Amazon SES (`includes/ses.php`):
- Moderation notifications on new pending comments
- Reply notifications when someone is replied to

If SES config values are empty, email sending will fail silently from a user perspective unless you add custom handling/logging.

## Local Development

Run with PHP built-in server:

```bash
php -S 127.0.0.1:8000 -t .
```

Then open:
- `http://127.0.0.1:8000/setup.php` (first run)
- `http://127.0.0.1:8000/login.php`
- `http://127.0.0.1:8000/`

## File Map

- `index.php` - admin panel
- `login.php` - login form
- `settings.php` - authenticated settings page
- `logout.php` - logout endpoint
- `setup.php` - first-run installer
- `api/index.php` - comments API
- `includes/admin_auth.php` - auth + rate limiting helpers
- `includes/config_builder.php` - shared `config.php` generation helper
- `includes/session.php` - secure session bootstrap
- `includes/db.php` - DB access and schema init
- `includes/render.php` - admin rendering + thread helpers
- `includes/ses.php` - Amazon SES sending
- `public/embed.js` - embeddable comment UI
- `public/style.css` - shared UI stylesheet
- `public/icons/sprite.svg` - SVG icon sprite
- `.htaccess` - config deny + optional admin IP allowlist

## Operational Recommendations

- Keep `config.php` out of version control.
- Keep DB (`db/comments.sqlite`) out of version control.
- Use HTTPS only.
- Rotate admin password periodically.
- Consider log/monitoring around failed logins and SES errors.

## Config Reference

- `db_path`: Filesystem path to your SQLite DB. Settings field: `No` (setup-generated/manual).
- `admin_username`: Username for admin login. Settings field: `Yes` (`Admin username`).
- `admin_password_hash`: Password hash for admin login (required for auth). Settings field: `Yes` (written when setting `Admin password`).
- `sodium_key`: Secret key used to encrypt/decrypt commenter emails. Do not rotate casually. Settings field: `No` (preserved automatically).
- `privacy_policy_url`: URL used by the frontend “Read the comment privacy notice” link. Settings field: `Yes` (`Privacy policy URL`).
- `spam_challenge.question`: Human check question shown on the comment form. Settings field: `Yes` (`Challenge question`).
- `spam_challenge.answer`: Expected answer for the human check (validated case-insensitively). Settings field: `Yes` (`Challenge answer`).
- `spam_challenge.placeholder`: Optional placeholder text for the human check input. Settings field: `Yes` (`Challenge placeholder`).
- `post_titles`: Optional slug-to-title map used in admin/email contexts for nicer post titles. Settings field: `No` (manual advanced config).
- `post_base_url`: Base URL of your blog posts (used to build links in admin emails). Settings field: `Yes` (`Post base URL`).
- `author.name`: Name used for “reply as author”. Settings field: `Yes` (`Author name`).
- `author.email`: Email used for “reply as author” and author-comment detection. Settings field: `Yes` (`Author email`).
- `aws.region`: AWS SES region (for sending moderation/reply emails). Settings field: `Yes` (`AWS region`).
- `aws.access_key`: AWS SES access key. Settings field: `Yes` (`AWS access key`).
- `aws.secret_key`: AWS SES secret key. Settings field: `Yes` (`AWS secret key`).
- `aws.source_email`: From email address for SES messages. Settings field: `Yes` (`SES source email`).
- `aws.source_name`: Optional display name for SES from address. Settings field: `Yes` (`SES source name`).
- `moderation.notify_email`: Email that receives “new comment awaiting moderation” notifications. Settings field: `Yes` (`Moderation notify email`).
- `moderation.base_url`: Base URL of this comments service, used in moderation emails. Settings field: `Yes` (`Comments service URL`).