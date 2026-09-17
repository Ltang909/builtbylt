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

## Deployment behavior

`.github/workflows/main.yml` remains unchanged. Pushes to `main` copy the repository to `domains/builtbylt.com/public_html`. The private configuration sits outside that target. Runtime activity lives in `storage/activity.json`; it is gitignored, and the workflow uses `rm: false`, so deploys preserve it.

## Security notes

- PHP verifies authentication with `password_verify`; this is not a JavaScript-only gate.
- Sessions use HTTP-only, SameSite cookies, strict session IDs, and a 12-hour lifetime.
- Five failed attempts trigger a short per-session cooldown.
- The activity API requires the authenticated session.
- Storage and configuration files are blocked from direct web access by `.htaccess`.
- Keep HTTPS enabled in Hostinger so session cookies are secure.


