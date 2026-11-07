# ULearn Stage 9 — UI Fix v2

Back up files first. This is a patch for the Stage 9 package, not a fresh database install. **Do not rerun migration 017** if already applied.

Copy the files into matching paths under `C:\xampp\htdocs\ulearn\` (including `admin/index.php`).

Admin links appear in the **Admin Overview sidebar**: People & Accounts and Academic Requests. Direct URLs: `/ulearn/admin/users.php` and `/ulearn/admin/change-requests.php`.

Profiles now have visual Light / Dark / System cards and a selectable 12-character original comic-avatar gallery. Save preference to persist theme. No emoji font is required for the avatar images.

This package updates the existing Stage 9 UI only; it does not include full login/register/dashboard redesign or AI bot integration. Compare with newer local changes before overwriting.
