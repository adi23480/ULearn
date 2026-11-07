-- Run once in the ULearn database using phpMyAdmin > SQL.
-- Does not alter or delete existing module assignments.
CREATE TABLE IF NOT EXISTS lecturer_degree_assignments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lecturer_id BIGINT UNSIGNED NOT NULL,
  degree_id BIGINT UNSIGNED NOT NULL,
  batch_id BIGINT UNSIGNED NOT NULL,
  study_year TINYINT UNSIGNED NOT NULL,
  semester TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 means all semesters',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lecturer_degree_scope (lecturer_id, degree_id, batch_id, study_year, semester),
  KEY idx_degree_scope (degree_id, batch_id, study_year, semester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
