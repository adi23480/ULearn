# ULearn Stage 9 v3 — profile uploads and cleaner selectors

Based on ulearn12.zip + Stage9 Integrated Fix.

1. Back up your current ULearn files and MySQL database.
2. Import database/migrations/018_profile_photos.sql in phpMyAdmin (run once).
3. Copy the included files to the corresponding paths in C:\xampp\htdocs\ulearn\. Do not overwrite unrelated files.
4. Make sure assets/uploads/profile-photos/ is writable by Apache. Its .htaccess blocks executable uploads on Apache.
5. Hard refresh (Ctrl+F5) and test student and lecturer profile uploads, remove photo, and admin user filters.

The comic avatar picker is removed from student/lecturer profile pages; the existing comic-avatar DB table is left untouched to avoid destructive migrations. Existing login/profile initials remain where separate dashboard components use them; a future shared header component can display uploaded photos across all screens.

Profile photos are private to the user's own upload/update actions. Uploaded images are stored in assets/uploads/profile-photos and are served as ordinary static images; avoid uploading sensitive photos. Only JPG, PNG, WebP under 3MB are accepted with image metadata and MIME validation. Full content re-encoding and malware scanning are not included.

Duplicate degree names in admin filters are collapsed by name; no degree records are deleted.
