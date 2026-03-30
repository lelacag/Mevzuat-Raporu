-- migrations for invites module

CREATE TABLE IF NOT EXISTS user_invitations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invited_by INT NOT NULL,
    invited_user INT DEFAULT NULL,
    email VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    status ENUM('pending','accepted','expired') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    accepted_at DATETIME DEFAULT NULL,
    INDEX idx_invites_by (invited_by),
    INDEX idx_invites_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
