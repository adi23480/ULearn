# U Learn — Authentication v4 (localhost)

## Install
1. Back up your existing `C:\xampp\htdocs\ulearn` folder.
2. Extract this ZIP and copy the **contents** of `ulearn_auth_v4` into `C:\xampp\htdocs\ulearn`, replacing matching files. Do not nest the folders.
3. Start Apache and MySQL in XAMPP.
4. In phpMyAdmin, Import `database/schema.sql` (safe to rerun: tables use IF NOT EXISTS).
5. Open http://localhost/ulearn/auth/register.php and register with a test email.
6. Click the **localhost development verification link** displayed after registration.
7. Open http://localhost/ulearn/auth/login.php and log in.
8. In the dashboard, change the theme and save.

## Important
- The verification link is **shown in the browser** only for local development; **no email is sent**.
- Never deploy this code publicly with `LOCAL_DEVELOPMENT=true`. Production requires an actual transactional email sender, a secret config outside the web root, rate limiting backed by persistent storage, HTTPS, and deployment review.
- Password reset, admin approval UI, resend verification and live email sending are not implemented yet.
- This assumes the standard XAMPP MySQL root account has a blank password. If yours differs, change `config/bootstrap.php` locally.
- The base path is `/ulearn`. If you named the folder differently, edit `APP_BASE` in `config/bootstrap.php`.
- If PHP says `Call to undefined function mb_strlen`, enable `mbstring` in XAMPP PHP extensions.
