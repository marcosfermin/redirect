CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin', 'editor', 'viewer') DEFAULT 'viewer',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE galleries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(64) NOT NULL UNIQUE,
  youtube_url TEXT NOT NULL,
  title VARCHAR(255) DEFAULT NULL,
  description TEXT DEFAULT NULL,
  meta_image TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE gallery_media (
  id INT AUTO_INCREMENT PRIMARY KEY,
  gallery_id INT NOT NULL,
  media_type ENUM('image', 'video') NOT NULL,
  file_path TEXT NOT NULL,
  original_filename VARCHAR(255) DEFAULT NULL,
  file_size INT UNSIGNED DEFAULT NULL,
  mime_type VARCHAR(100) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE,
  INDEX idx_gallery_id (gallery_id)
);

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('meta_refresh_seconds', '5');

CREATE TABLE IF NOT EXISTS completions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(64) NOT NULL,
  ip_address VARCHAR(64),
  user_agent TEXT,
  country VARCHAR(2) DEFAULT NULL,
  watch_time INT UNSIGNED DEFAULT 0,
  completed_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
