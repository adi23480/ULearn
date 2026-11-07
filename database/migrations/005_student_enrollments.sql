-- ULearn migration 005: student academic enrollments.
-- Import once in phpMyAdmin after academic structure migration 003.
-- No existing academic or lecturer records are modified.
CREATE TABLE IF NOT EXISTS student_enrollments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id BIGINT UNSIGNED NOT NULL,
  degree_id INT UNSIGNED NOT NULL,
  batch_id INT UNSIGNED NOT NULL,
  study_year TINYINT UNSIGNED NOT NULL,
  semester TINYINT UNSIGNED NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_student_degree_batch (student_id, degree_id, batch_id),
  KEY idx_student_enrollment_class (degree_id, batch_id, study_year, semester),
  CONSTRAINT fk_student_enroll_user FOREIGN KEY (student_id) REFERENCES users(id),
  CONSTRAINT fk_student_enroll_degree_batch FOREIGN KEY (degree_id, batch_id)
    REFERENCES academic_degree_intakes(degree_id, batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
