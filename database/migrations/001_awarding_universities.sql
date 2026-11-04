
CREATE TABLE IF NOT EXISTS awarding_universities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    code VARCHAR(40) NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE academic_degrees
ADD COLUMN awarding_university_id INT UNSIGNED NULL,
ADD CONSTRAINT fk_degree_university
FOREIGN KEY (awarding_university_id)
REFERENCES awarding_universities(id);
