-- migrations for invites module

CREATE TABLE IF NOT EXISTS invite_settings (
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT DEFAULT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- default invitation templates (subject/body) in Turkish; tokens: {site_name}, {invite_link}, {expiry_days}
INSERT IGNORE INTO invite_settings (setting_key, setting_value) VALUES
('email_subject', 'Seni {site_name} sitesine davet ediyoruz'),
('email_body', 'Merhaba,\n\n{site_name} sitesine davet edildiniz. Aşağıdaki bağlantı ile kayıt olabilirsiniz:\n\n{invite_link}\n\nBu bağlantı {expiry_days} gün geçerlidir.');
