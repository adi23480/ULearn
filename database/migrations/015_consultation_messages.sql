-- Run once after migrations 013 and 014. Back up your database first.
CREATE TABLE IF NOT EXISTS consultation_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  sender_user_id INT NOT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_consult_messages_booking (booking_id, id),
  KEY idx_consult_messages_sender (sender_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
