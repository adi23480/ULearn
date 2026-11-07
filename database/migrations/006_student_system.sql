-- Run ONCE after backing up the ulearn database. Does not delete existing records.
ALTER TABLE users ADD COLUMN student_number VARCHAR(60) NULL, ADD COLUMN account_status ENUM('active','suspended') NOT NULL DEFAULT 'active', ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1;
CREATE UNIQUE INDEX uq_student_number ON users(student_number);
CREATE TABLE IF NOT EXISTS academic_intake_progression (
 degree_id INT UNSIGNED NOT NULL, batch_id INT UNSIGNED NOT NULL,
 study_year TINYINT UNSIGNED NOT NULL DEFAULT 1, semester TINYINT UNSIGNED NOT NULL DEFAULT 1,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(degree_id,batch_id),
 FOREIGN KEY(degree_id) REFERENCES academic_degrees(id), FOREIGN KEY(batch_id) REFERENCES academic_batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO academic_intake_progression(degree_id,batch_id,study_year,semester)
 SELECT degree_id,batch_id,1,1 FROM academic_degree_intakes;
CREATE TABLE IF NOT EXISTS student_account_audit (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, student_user_id BIGINT UNSIGNED NOT NULL,
 admin_user_id BIGINT UNSIGNED NOT NULL, action VARCHAR(40) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(student_user_id) REFERENCES users(id), FOREIGN KEY(admin_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Existing student_academic_profiles remains the canonical student degree/intake record.
-- Existing student_enrollments is NOT deleted; it is not used by this update.
