-- Back up your database before importing. Run ONCE in phpMyAdmin.
-- Existing lecturer accounts and teaching assignments are preserved.
CREATE TABLE IF NOT EXISTS lecturer_invitations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 email VARCHAR(254) NOT NULL,
 code_hash VARCHAR(255) NOT NULL,
 title VARCHAR(16) NOT NULL DEFAULT 'Mr.',
 department VARCHAR(150) NULL,
 designation VARCHAR(150) NULL,
 invited_by BIGINT UNSIGNED NULL,
 expires_at DATETIME NOT NULL,
 sent_at DATETIME NOT NULL,
 used_at DATETIME NULL,
 attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
 UNIQUE KEY uq_lecturer_invitation_email (email),
 KEY idx_lecturer_invitation_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE users ADD COLUMN username VARCHAR(60) NULL;
ALTER TABLE lecturer_profiles ADD COLUMN lecturer_number VARCHAR(60) NULL;
CREATE UNIQUE INDEX uq_ulearn_username ON users(username);
CREATE UNIQUE INDEX uq_ulearn_lecturer_number ON lecturer_profiles(lecturer_number);
