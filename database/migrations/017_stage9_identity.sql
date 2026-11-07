-- Apply once in phpMyAdmin, after backing up your database.
CREATE TABLE IF NOT EXISTS academic_change_requests (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 student_user_id BIGINT UNSIGNED NOT NULL,
 requested_degree_id BIGINT UNSIGNED NOT NULL,
 requested_batch_id BIGINT UNSIGNED NOT NULL,
 reason VARCHAR(1000) NOT NULL,
 status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
 admin_user_id BIGINT UNSIGNED NULL,
 admin_note VARCHAR(1000) NULL,
 reviewed_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_student_status (student_user_id,status), INDEX idx_status_created(status,created_at),
 CONSTRAINT fk_stage9_request_student FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_stage9_request_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS user_comic_avatars (
 user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 avatar_key VARCHAR(32) NOT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_stage9_avatar_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
