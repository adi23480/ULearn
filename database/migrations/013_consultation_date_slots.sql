-- ULearn Consultation V2: date-specific appointment start times.
-- Safe additive migration; existing booking and legacy weekly availability tables remain untouched.
CREATE TABLE IF NOT EXISTS consultation_date_slots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lecturer_id INT NOT NULL,
    available_date DATE NOT NULL,
    start_time TIME NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_consult_lecturer_date_time (lecturer_id, available_date, start_time),
    KEY idx_consult_slot_date (available_date, is_active, lecturer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
