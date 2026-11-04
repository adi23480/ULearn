
-- ULearn Migration 003
-- Academic structure and degree intakes

-- 1. Add degree duration (run once only)
ALTER TABLE academic_degrees
ADD COLUMN duration_years TINYINT UNSIGNED NOT NULL DEFAULT 4;

-- 2. Link degree programmes to intake batches
CREATE TABLE IF NOT EXISTS academic_degree_intakes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    degree_id INT UNSIGNED NOT NULL,
    batch_id INT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_degree_intake (degree_id, batch_id),

    CONSTRAINT fk_adi_degree
        FOREIGN KEY (degree_id)
        REFERENCES academic_degrees(id),

    CONSTRAINT fk_adi_batch
        FOREIGN KEY (batch_id)
        REFERENCES academic_batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Preserve existing degree/intake relationships
INSERT IGNORE INTO academic_degree_intakes
    (degree_id, batch_id, is_active)
SELECT DISTINCT degree_id, batch_id, 1
FROM academic_curriculum;
