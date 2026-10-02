-- A atualização v6 é aplicada automaticamente por ensure_v6_schema().
-- Este arquivo serve como referência das novas estruturas.
ALTER TABLE users ADD COLUMN username VARCHAR(80) NULL AFTER name;

CREATE TABLE IF NOT EXISTS password_reset_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  purpose VARCHAR(30) NOT NULL DEFAULT 'forgot',
  code_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  used_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reset_user_purpose (user_id, purpose, used_at),
  INDEX idx_reset_expires (expires_at),
  CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
