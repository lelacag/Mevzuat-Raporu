-- ========================================
-- Complete Database Schema
-- Social Media Platform - Purple Theme
-- Created: 2026-01-14
-- ========================================

-- Create database
CREATE DATABASE IF NOT EXISTS textsocialmedia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE textsocialmedia;

-- ========================================
-- CORE TABLES
-- ========================================

-- Users table
CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) UNIQUE NOT NULL,
  `email` VARCHAR(255) UNIQUE DEFAULT NULL,
  `birthday` DATE NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `reset_token` VARCHAR(64) DEFAULT NULL,
  `reset_token_expiry` DATETIME DEFAULT NULL,
  `bio` TEXT DEFAULT NULL,
  `role` ENUM('rookie','member','admin') NOT NULL DEFAULT 'rookie',
  `is_premium` TINYINT(1) DEFAULT 0,
  `premium_until` DATETIME NULL,
  `suspended_until` DATETIME DEFAULT NULL,
  `event_code` VARCHAR(12) DEFAULT NULL,
  `notify_by_email` TINYINT(1) NOT NULL DEFAULT 0,
  `notify_on_mention` TINYINT(1) NOT NULL DEFAULT 1,
  `notify_on_reply` TINYINT(1) NOT NULL DEFAULT 1,
  `notify_on_report` TINYINT(1) NOT NULL DEFAULT 1,
  `notify_on_system` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `slug` VARCHAR(255) UNIQUE DEFAULT NULL,
  `accepted_terms` TINYINT(1) NOT NULL DEFAULT 0,
  `accepted_privacy` TINYINT(1) NOT NULL DEFAULT 0,
  `accepted_kvkk` TINYINT(1) NOT NULL DEFAULT 0,
  `accepted_cookies` TINYINT(1) NOT NULL DEFAULT 0,
  `accepted_terms_at` DATETIME DEFAULT NULL,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  INDEX `idx_username` (`username`),
  INDEX `idx_email` (`email`),
  INDEX `idx_role` (`role`),
  INDEX `idx_is_premium` (`is_premium`),
  UNIQUE INDEX `uniq_event_code` (`event_code`),
  INDEX `idx_event_code` (`event_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Posts table
CREATE TABLE IF NOT EXISTS `posts` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `content` TEXT NOT NULL,
  `parent_id` BIGINT UNSIGNED DEFAULT NULL,
  `approved` TINYINT(1) NOT NULL DEFAULT 1,
  `has_censored_words` TINYINT(1) DEFAULT 0,
  `review_status` ENUM('pending', 'approved', 'auto_approved') DEFAULT NULL,
  `approved_by` BIGINT UNSIGNED DEFAULT NULL,
  `approved_at` TIMESTAMP NULL DEFAULT NULL,
  `likes_count` INT DEFAULT 0,
  `replies_count` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_parent_id` (`parent_id`),
  INDEX `idx_created_at` (`created_at`),
  INDEX `idx_approved` (`approved`),
  INDEX `idx_review_status` (`review_status`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`parent_id`) REFERENCES `posts`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Likes table
CREATE TABLE IF NOT EXISTS `likes` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `post_id` BIGINT UNSIGNED NOT NULL,
  `reaction` VARCHAR(10) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_like` (`user_id`, `post_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`post_id`) REFERENCES `posts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Follows table
CREATE TABLE IF NOT EXISTS `follows` (
  `follower_id` BIGINT UNSIGNED NOT NULL,
  `following_id` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`follower_id`, `following_id`),
  FOREIGN KEY (`follower_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`following_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notifications table
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `type` ENUM('like','reply','follow','account_approved','report','suspended','unsuspended','mention') NOT NULL,
  `from_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `post_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `read_at` TIMESTAMP NULL DEFAULT NULL,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_created_at` (`created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`from_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`post_id`) REFERENCES `posts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Announcements table (site news / blog posts)
CREATE TABLE IF NOT EXISTS `announcements` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(255) NOT NULL,
  `summary` TEXT NOT NULL,
  `content` TEXT NOT NULL,
  `sources` TEXT DEFAULT NULL,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_slug` (`slug`),
  INDEX `idx_is_active` (`is_active`),
  INDEX `idx_created_at` (`created_at`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reports table
CREATE TABLE IF NOT EXISTS `reports` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `reporter_id` BIGINT UNSIGNED DEFAULT NULL,
  `target_type` ENUM('post','reply') NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL,
  `reason` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `status` ENUM('open','resolved') NOT NULL DEFAULT 'open',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_target_id` (`target_id`),
  INDEX `idx_created_at` (`created_at`),
  INDEX `idx_status` (`status`),
  FOREIGN KEY (`reporter_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`target_id`) REFERENCES `posts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- BADGE SYSTEM
-- ========================================

-- Badges table
CREATE TABLE IF NOT EXISTS `badges` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(100) NOT NULL UNIQUE,
  `description` TEXT DEFAULT NULL,
  `min_likes` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User badges table
CREATE TABLE IF NOT EXISTS `user_badges` (
  `user_id` BIGINT UNSIGNED NOT NULL,
  `badge_id` BIGINT UNSIGNED NOT NULL,
  `assigned_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `badge_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`badge_id`) REFERENCES `badges`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`assigned_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- PREMIUM SYSTEM
-- ========================================

-- Premium subscriptions table
CREATE TABLE IF NOT EXISTS `premium_subscriptions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `plan_type` ENUM('monthly', 'yearly', 'lifetime') DEFAULT 'monthly',
  `status` ENUM('pending', 'active', 'expired', 'cancelled') DEFAULT 'pending',
  `start_date` DATETIME DEFAULT NULL,
  `end_date` DATETIME DEFAULT NULL,
  `amount` DECIMAL(10,2) DEFAULT 0.00,
  `payment_method` VARCHAR(50) DEFAULT NULL,
  `payment_proof` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_status` (`status`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Premium settings table
CREATE TABLE IF NOT EXISTS `premium_settings` (
  `setting_key` VARCHAR(50) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Custom badges for premium users
CREATE TABLE IF NOT EXISTS `user_custom_badges` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `badge_text` VARCHAR(30) DEFAULT NULL,
  `badge_color` VARCHAR(7) DEFAULT '#ffd700',
  `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_status` (`status`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Events table (premium feature)
CREATE TABLE IF NOT EXISTS `events` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `event_date` DATETIME NOT NULL,
  `created_by` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_active` TINYINT(1) DEFAULT 1,
  INDEX `idx_event_date` (`event_date`),
  INDEX `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Event comments table
CREATE TABLE IF NOT EXISTS `events_comments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `event_id` INT NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `content` TEXT NOT NULL,
  `image_path` VARCHAR(1024) DEFAULT NULL,
  `likes_count` INT NOT NULL DEFAULT 0,
  `reports_count` INT NOT NULL DEFAULT 0,
  `parent_id` INT DEFAULT NULL,
  `replies_count` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL,
  INDEX `idx_event` (`event_id`),
  INDEX `idx_user` (`user_id`),
  INDEX `idx_parent` (`parent_id`),
  FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Likes for event comments
CREATE TABLE IF NOT EXISTS `event_comment_likes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `comment_id` INT NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_comment` (`comment_id`),
  INDEX `idx_user` (`user_id`),
  FOREIGN KEY (`comment_id`) REFERENCES `events_comments`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reports for event comments
CREATE TABLE IF NOT EXISTS `event_comment_reports` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `comment_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `reason` VARCHAR(1024) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_comment` (`comment_id`),
  INDEX `idx_user` (`user_id`),
  FOREIGN KEY (`comment_id`) REFERENCES `events_comments`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Post edit history table (premium feature)
CREATE TABLE IF NOT EXISTS `post_edits` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `post_id` BIGINT UNSIGNED NOT NULL,
  `original_content` TEXT NOT NULL,
  `edited_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_post_id` (`post_id`),
  FOREIGN KEY (`post_id`) REFERENCES `posts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- CONTENT MODERATION
-- ========================================

-- Bad words table
CREATE TABLE IF NOT EXISTS `bad_words` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `word` VARCHAR(100) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT DEFAULT NULL,
  UNIQUE KEY `word` (`word`),
  INDEX `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- DEFAULT DATA
-- ========================================

-- Insert default premium settings
INSERT INTO `premium_settings` (`setting_key`, `setting_value`) VALUES
('monthly_price', '5.00'),
('yearly_price', '50.00'),
('lifetime_price', '150.00'),
('currency', 'USD'),
('premium_post_limit', '5000'),
('premium_enabled', '1'),
('similarity_threshold', '75')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- ========================================
-- SMART FILTERING SYSTEM
-- ========================================

-- Approved words whitelist (for bypass detection)
CREATE TABLE IF NOT EXISTS `approved_words` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `word` VARCHAR(255) NOT NULL,
  `approved_by` BIGINT UNSIGNED NOT NULL,
  `approved_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_word` (`word`),
  FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_word` (`word`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- VERIFICATION QUERIES
-- ========================================

-- Check all tables
SHOW TABLES;

-- Check user structure
DESCRIBE users;

-- Check posts structure
DESCRIBE posts;

-- Check indexes
SHOW INDEX FROM users;
SHOW INDEX FROM posts;

-- ========================================
-- END OF SCHEMA
-- ========================================
