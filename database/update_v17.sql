UPDATE users SET role='user' WHERE role='manager';
ALTER TABLE users MODIFY role ENUM('admin','user') NOT NULL DEFAULT 'user';
UPDATE users SET role='admin' WHERE username='felippe.andreata';

CREATE TABLE IF NOT EXISTS reminder_assignees (
  reminder_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (reminder_id,user_id),
  INDEX idx_reminder_assignees_user (user_id,reminder_id),
  CONSTRAINT fk_reminder_assignees_reminder FOREIGN KEY (reminder_id) REFERENCES simple_tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_reminder_assignees_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
