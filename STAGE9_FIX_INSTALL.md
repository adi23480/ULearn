# ULearn Stage 9 integrated admin fix

Based on uploaded ulearn12.zip. BACK UP FILES AND DATABASE FIRST.

Copy the admin/, dashboard/profile.php and assets/css/admin-stage9.css files from this package into your existing ULearn directory, preserving paths. Do not overwrite unrelated files. No new SQL migration required if Stage 9 migration 017 was already installed.

Admin navigation: Admin overview → System Management → All Registered Users / Academic Change Requests. Direct: /ulearn/admin/users.php and /ulearn/admin/change-requests.php. Click View / Edit on an account to manage it.

Account removal is intentionally implemented as reversible Suspend, not hard delete: deleting accounts with academic records risks breaking history and foreign keys. Admin accounts cannot be modified on this page. Student academic degree/intake remains locked; use Academic Requests.

Student academic requests now notify all active admins through the existing notifications table; review decisions already notify the student.

Character art: this patch does not include official Marvel/DC images. Those require appropriate rights to redistribute and display; supply licensed character portraits and we can map them to the existing avatar IDs. Existing original avatars are preserved.

Testing: log in as admin, verify sidebar, directory filters, view/edit, suspend/reactivate, notifications; test dark/light/system theme. Test with a non-production database. PHP lint is not a live DB test.
