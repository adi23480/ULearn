
USE ulearn;

-- =====================================
-- TABLE 1: STUDENT SCORES
-- Stores each student's current score
-- =====================================

CREATE TABLE IF NOT EXISTS student_scores (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    total_score INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_student (student_id),

    CONSTRAINT fk_student_scores_user
        FOREIGN KEY (student_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;


-- =====================================
-- TABLE 2: SCORE TRANSACTIONS
-- Stores every point-earning activity
-- =====================================

CREATE TABLE IF NOT EXISTS score_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    activity_type VARCHAR(50) NOT NULL,
    activity_id BIGINT UNSIGNED NOT NULL,
    points INT NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY unique_activity_score
        (student_id, activity_type, activity_id),

    INDEX idx_student_transactions (student_id),

    CONSTRAINT fk_score_transactions_user
        FOREIGN KEY (student_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;


-- =====================================
-- TABLE 3: SCORING RULES
-- Stores points for each activity
-- =====================================

CREATE TABLE IF NOT EXISTS scoring_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_type VARCHAR(50) NOT NULL,
    points INT NOT NULL DEFAULT 0,
    description VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_activity_type (activity_type)
) ENGINE=InnoDB;


-- =====================================
-- INSERT DEFAULT SCORING RULES
-- =====================================

INSERT IGNORE INTO scoring_rules
(activity_type, points, description, is_active)
VALUES
('quiz_correct_answer', 5, 'Points for each correct quiz answer', 1),
('quiz_completion', 10, 'Points for completing a quiz', 1),
('perfect_quiz_bonus', 20, 'Bonus for a perfect quiz score', 1),
('assignment_submission', 15, 'Points for submitting an assignment', 1),
('assignment_full_marks', 20, 'Bonus for full assignment marks', 1),
('learning_material', 5, 'Points for completing learning materials', 1),
('academic_activity', 10, 'Points for participating in academic activities', 1),
('workshop_attendance', 15, 'Points for attending workshops', 1);


-- =====================================
-- VERIFY TABLES
-- =====================================

SHOW TABLES;

SELECT * FROM scoring_rules;
SELECT * FROM student_scores;
SELECT * FROM score_transactions;


