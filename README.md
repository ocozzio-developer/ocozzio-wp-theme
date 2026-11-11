# Ocozzio WordPress base theme

The parent theme (`ocozzio/`) of every client WordPress theme built with the starter repo. Client themes are child themes (`Template: ocozzio`) holding only their generated pages, header/footer, CSS and JS.

Sites update this theme themselves: the theme-deployer MU plugin (in the starter repo, `wp-deploy/`) checks this repo's update feed with plugin-update-checker and lets WordPress install new versions in the background.

## What's in the theme
- `functions.php`: menus (Primary, Footer), custom logo, `square-thumb` (750×750 crop), cache-busted loading of the child theme's `style.css`, `js/main.js` and `css/templates/<page>.css`, nav classes for the starter's markup, classic editor, shortcodes in text widgets, the site logo on the login screen, external links in a new tab, 404s redirected to the homepage (301).
- `inc/tracking.php`: **Tracking & Consent** dashboard widget. Sets the GTM container ID, Google Consent Mode v2 defaults (toggle; everything denied until the banner grants consent), cookie banner snippets (Termly, CookieYes, …: one for the head, before GTM, and one for just after `<body>` opens) and the UserWay account ID (accessibility widget, footer). Each embed prints only when it's set. Editing needs `manage_options` + `unfiltered_html`. Child headers must call `wp_body_open()`. Filter `ocozzio_consent_defaults` to change the consent defaults.
- `inc/activity-log.php`: permanent activity log in `{prefix}ocozzio_activity_log`. Records logins/failed logins, user changes, content status changes/edits/deletes, media, plugin/theme/core installs and updates, exports and key settings (filter `ocozzio_log_watched_options`). Shown in a **Recent Activity** dashboard widget and at Dashboard → Activity Log. Never purged and not deletable from the UI; the table survives theme switches. Log your own events with `ocozzio_log( $action, $message, $args )`. IPs come from `REMOTE_ADDR`; filter `ocozzio_log_ip` behind a trusted proxy.
- `header.php`, `footer.php`, `index.php`, `page.php`, `single.php`: fallbacks. Client themes generate their own header/footer.
- Client-prefixed classes (`rxp-nav-item`, …) come from `ocozzio_prefix()`: the child theme folder name without `-theme` (`rxp-theme` → `rxp`). Filter `ocozzio_prefix` to override.
- PHP functions use `ocozzio_`, handles `ocozzio-`, text domain `ocozzio`.

## Releasing
Both workflows run from **Actions** and wait for approval from a reviewer on the `release` environment.

1. **Release (beta):** PHP syntax check, version `<major.minor from style.css>.<run number>` stamped into the zipped `style.css`, `ocozzio.zip` published as a GitHub **pre-release**, and `update-beta.json` pointed at it. Our dev site (`THEME_PARENT_CHANNEL` = `beta`) installs it on its next update check.
2. Check the dev site.
3. **Promote (stable):** marks the release as the latest stable release and points `update.json` at it. Client sites (`stable`) install it on their next update check (about twice a day, when the site has traffic).

To change the major/minor version, edit `Version:` in `ocozzio/style.css`; the patch number is always the run number.

## Update feed
The feed lives on the `update-feed` branch, written only by the workflows:
- `update.json`: stable, read by client sites
- `update-beta.json`: beta, read by the dev site

Sites read them from `https://raw.githubusercontent.com/ocozzio-developer/ocozzio-wp-theme/update-feed/<file>`, which is not subject to GitHub's API rate limit. Downloads come from the release assets.

## One-time GitHub setup
- Make the repo **public** (sites download releases without credentials).
- Org: require 2FA.
- Protect `main` (pull requests, no force pushes).
- Create a `release` environment with required reviewers; both workflows use it.
- Allow GitHub Actions to push to `update-feed` (if branch rules cover all branches, add an exception for Actions on `update-feed`).
- Run **Release** then **Promote** once before setting up the first site, so `update.json` exists and the setup command has a stable release to install.
