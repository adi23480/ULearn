-- Run AFTER 009_lecturer_invitations.sql. Back up database first.
-- Existing invitations are preserved but must be resent with their required scope.
ALTER TABLE lecturer_invitations
 ADD COLUMN university_id INT UNSIGNED NULL,
 ADD COLUMN degree_id INT UNSIGNED NULL,
 ADD COLUMN batch_id INT UNSIGNED NULL,
 ADD COLUMN study_year TINYINT UNSIGNED NULL,
 ADD COLUMN semester TINYINT UNSIGNED NULL;
CREATE INDEX idx_lecturer_invitation_scope ON lecturer_invitations(degree_id,batch_id,study_year,semester);
