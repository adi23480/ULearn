
CREATE TABLE qa_questions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    module_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    question TEXT NOT NULL,
    best_answer_id BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_qa_module (module_id),
    INDEX idx_qa_user (user_id),

    CONSTRAINT fk_qa_question_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_qa_question_module
        FOREIGN KEY (module_id) REFERENCES academic_modules(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE qa_answers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    answer TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_qa_answer_question (question_id),
    INDEX idx_qa_answer_user (user_id),

    CONSTRAINT fk_qa_answer_question
        FOREIGN KEY (question_id) REFERENCES qa_questions(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_qa_answer_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE qa_answer_likes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    answer_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY unique_answer_like (answer_id, user_id),

    CONSTRAINT fk_qa_like_answer
        FOREIGN KEY (answer_id) REFERENCES qa_answers(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_qa_like_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
