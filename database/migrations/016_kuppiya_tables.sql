-- ULearn Stage 7: Kuppiya schema. Run once after selecting your ULearn database.
-- CREATE TABLE IF NOT EXISTS does not overwrite existing Kuppiya data.
CREATE TABLE IF NOT EXISTS kuppiya_sessions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 module_id BIGINT UNSIGNED NULL,
 host_user_id BIGINT UNSIGNED NOT NULL,
 invite_token CHAR(48) NOT NULL,
 title VARCHAR(180) NOT NULL,
 description TEXT NULL,
 location_type ENUM('online','in_person') NOT NULL DEFAULT 'online',
 meeting_details VARCHAR(500) NULL,
 starts_at DATETIME NOT NULL,
 ends_at DATETIME NOT NULL,
 max_members TINYINT UNSIGNED NOT NULL DEFAULT 12,
 status ENUM('scheduled','cancelled','completed') NOT NULL DEFAULT 'scheduled',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_kuppiya_invite (invite_token),
 KEY idx_kuppiya_schedule (status,starts_at,ends_at),
 KEY idx_kuppiya_host (host_user_id),
 CONSTRAINT fk_kuppiya_host FOREIGN KEY (host_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kuppiya_members (
 session_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (session_id,user_id),
 KEY idx_kuppiya_member_user (user_id),
 CONSTRAINT fk_kuppiya_member_session FOREIGN KEY (session_id) REFERENCES kuppiya_sessions(id) ON DELETE CASCADE,
 CONSTRAINT fk_kuppiya_member_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kuppiya_messages (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 session_id BIGINT UNSIGNED NOT NULL,
 sender_user_id BIGINT UNSIGNED NULL,
 message_type ENUM('chat','system') NOT NULL DEFAULT 'chat',
 message_text TEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_kuppiya_message_order (session_id,id),
 CONSTRAINT fk_kuppiya_message_session FOREIGN KEY (session_id) REFERENCES kuppiya_sessions(id) ON DELETE CASCADE,
 CONSTRAINT fk_kuppiya_message_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
