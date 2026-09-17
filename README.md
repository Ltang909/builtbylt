# Built by LT Portal V1

A private founder dashboard for `builtbylt.com`: portfolio status, daily shipping count, timestamped activity, and a fast manual update log. There is no build step or database dependency; PHP writes activity to a protected JSON file with file locking.

## One-time Hostinger setup

The portal refuses to show the dashboard until a real server-side password hash exists. No password or secret belongs in this repository.

1. Generate a password hash on any machine with PHP:
   ```sh
   php -r "echo password_hash('CHOOSE A LONG PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"
   ```
2. In Hostinger File Manager, create `domains/builtbylt.com/private/builtbylt.php` (a sibling of `public_html`, not inside it).
3. Copy the shape from `private-config.example.php` and paste in the generated hash:
   ```php
   <?php
   return [
       'password_hash' => '$2y$...the full generated hash...',
       'timezone' => 'America/Toronto',
   ];
   ```
4. Ensure PHP can write to `public_html/storage`. A typical Hostinger permission of `750` or `755` is sufficient.
5. Open `https://builtbylt.com`, sign in, and add the first entry.

The app also accepts `PORTAL_PASSWORD_HASH` and `PORTAL_TIMEZONE` environment variables.

## Optional PostHog connection

When connected, the dashboard reads 30-day page views and unique visitors per domain and sends every manual log entry to PostHog as an `update_shipped` event.

Add the `posthog` block shown in `private-config.example.php` to the private configuration file. You need the numeric project ID, a Personal API key restricted to **Query Read**, and the normal `phc_...` project key for capture. Keep the Personal API key server-side and never commit it or place it in browser JavaScript.

The example uses PostHog US hosts. EU projects should use the EU hosts shown in PostHog settings. Website analytics must send `$pageview` with `$host`; cards match that host to the exact business domain. Metrics are cached for ten minutes.

## Optional Google Calendar connection

The planning rail can show the next six calendar blocks without putting Google credentials in browser code. In Google Calendar, open **Settings → Integrate calendar**, copy the **Secret address in iCal format**, and add it as `calendar_ics_url` in the private `builtbylt.php` file. Treat that URL like a password and never commit it. The dashboard reads it server-side and does not modify calendar events.

Upcoming ideas are stored in `storage/ideas.json`, alongside the protected runtime data. Add Claude-scraped ideas through the visible Ideas to ship form; no scraper credentials are required by the portal.

## Deployment behavior

`.github/workflows/main.yml` remains unchanged. Pushes to `main` copy the repository to `domains/builtbylt.com/public_html`. The private configuration sits outside that target. Runtime activity lives in `storage/activity.json`; it is gitignored, and the workflow uses `rm: false`, so deploys preserve it.

## Security notes

- PHP verifies authentication with `password_verify`; this is not a JavaScript-only gate.
- Sessions use HTTP-only, SameSite cookies, strict session IDs, and a 12-hour lifetime.
- Five failed attempts trigger a short per-session cooldown.
- The activity API requires the authenticated session.
- Storage and configuration files are blocked from direct web access by `.htaccess`.
- Keep HTTPS enabled in Hostinger so session cookies are secure.

