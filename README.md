# U Learn — Balanced Creative Landing v3

## Install on XAMPP (Windows)
1. Make a backup of your current `C:\xampp\htdocs\ulearn` folder.
2. Open this package's `ulearn` folder.
3. Copy **index.php**, **assets/css/style.css**, and **assets/js/theme.js** into the matching paths in your existing `C:\xampp\htdocs\ulearn` folder. Choose Replace.
4. Start Apache in XAMPP. Visit `http://localhost/ulearn/` and press Ctrl+F5.

The package also includes the original starter folders and database schema for a fresh install. If installing fresh, copy the full `ulearn` folder into `htdocs` instead.

## What works
- Responsive PHP landing page
- Light/dark theme toggle (localStorage)
- Animated hero, dashboard preview, scroll reveal, reading-progress indicator
- Interactive 3-state story section with accessible buttons
- Reduced-motion support

## What is a preview only
- Dashboard data, quiz results, sessions and course progress are **illustrative**.
- Registration, login, email verification, admin roles and backend integrations are **not implemented yet**.
- Google Fonts need internet; local fallback fonts are used offline.

## Files
- `index.php` page markup
- `assets/css/style.css` design and animations
- `assets/js/theme.js` theme, reveal, progress and story interactions
- `database/schema.sql` starter schema (no changes from v2)
