-- Import once before replacing PHP files. Existing quizzes remain available to their existing module enrollees.
CREATE TABLE IF NOT EXISTS quiz_teaching_targets (
  quiz_id BIGINT UNSIGNED NOT NULL,
  degree_id INT UNSIGNED NOT NULL,
  batch_id INT UNSIGNED NOT NULL,
  study_year TINYINT UNSIGNED NOT NULL,
  semester TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (quiz_id, degree_id, batch_id, study_year, semester),
  KEY idx_quiz_target_student (degree_id, batch_id, study_year, semester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
