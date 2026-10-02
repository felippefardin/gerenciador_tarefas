USE gerenciador_tarefas;

CREATE TABLE IF NOT EXISTS email_change_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  new_email VARCHAR(180) NOT NULL,
  current_code_hash VARCHAR(255) NOT NULL,
  new_code_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  used_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_email_change_user (user_id, used_at, created_at),
  INDEX idx_email_change_expires (expires_at),
  CONSTRAINT fk_email_change_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
