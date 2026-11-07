-- Run after 011. Back up the database first. Preserve existing module-linked quizzes.
ALTER TABLE quizzes MODIFY COLUMN module_id INT UNSIGNED NULL DEFAULT NULL;
-- Existing quiz_teaching_targets records are preserved; programme-level targets use year=0 and semester=0.
