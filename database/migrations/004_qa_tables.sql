-- 1. QA QUESTIONS
CREATE TABLE IF NOT EXISTS qa_questions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    code_snippet MEDIUMTEXT NULL,
    code_language VARCHAR(50) NULL,
    is_solved TINYINT(1) NOT NULL DEFAULT 0,
    solved_answer_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_qa_questions_user (user_id),
    INDEX idx_qa_questions_created (created_at),
    INDEX idx_qa_questions_solved (is_solved),

    CONSTRAINT fk_qa_questions_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- 2. QA ANSWERS
CREATE TABLE IF NOT EXISTS qa_answers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    description TEXT NOT NULL,
    code_snippet MEDIUMTEXT NULL,
    code_language VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_qa_answers_question (question_id),
    INDEX idx_qa_answers_user (user_id),

    CONSTRAINT fk_qa_answers_question
        FOREIGN KEY (question_id)
        REFERENCES qa_questions(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_qa_answers_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- 3. SOLVED ANSWER FOREIGN KEY
ALTER TABLE qa_questions
ADD CONSTRAINT fk_qa_questions_solved_answer
    FOREIGN KEY (solved_answer_id)
    REFERENCES qa_answers(id)
    ON DELETE SET NULL;


-- 4. QA VOTES
CREATE TABLE IF NOT EXISTS qa_votes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    question_id BIGINT UNSIGNED NULL,
    answer_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NOT NULL,

    vote_value TINYINT NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_question_user_vote (question_id, user_id),
    UNIQUE KEY unique_answer_user_vote (answer_id, user_id),

    INDEX idx_qa_votes_user (user_id),

    CONSTRAINT chk_qa_vote_target
        CHECK (
            (question_id IS NOT NULL AND answer_id IS NULL)
            OR
            (question_id IS NULL AND answer_id IS NOT NULL)
        ),

    CONSTRAINT chk_qa_vote_value
        CHECK (vote_value IN (-1, 1)),

    CONSTRAINT fk_qa_votes_question
        FOREIGN KEY (question_id)
        REFERENCES qa_questions(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_qa_votes_answer
        FOREIGN KEY (answer_id)
        REFERENCES qa_answers(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_qa_votes_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. QA ATTACHMENTS
CREATE TABLE IF NOT EXISTS qa_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id BIGINT UNSIGNED NULL,
    answer_id BIGINT UNSIGNED NULL,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_qa_attachments_question (question_id),
    INDEX idx_qa_attachments_answer (answer_id),
    INDEX idx_qa_attachments_user (uploaded_by),

    CONSTRAINT chk_qa_attachment_owner
        CHECK (
            (question_id IS NOT NULL AND answer_id IS NULL)
            OR
            (question_id IS NULL AND answer_id IS NOT NULL)
        ),

    CONSTRAINT fk_qa_attachments_question
        FOREIGN KEY (question_id)
        REFERENCES qa_questions(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_qa_attachments_answer
        FOREIGN KEY (answer_id)
        REFERENCES qa_answers(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_qa_attachments_user
        FOREIGN KEY (uploaded_by)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;