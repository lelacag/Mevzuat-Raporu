-- Migration 001: Add missing indexes and scheduled_at column
-- Applied: 2026-04-01
-- Purpose: Reliability improvement Phase 3 — database hardening

-- Composite indexes for notification performance
ALTER TABLE notifications ADD INDEX idx_user_unread (user_id, read_at);
ALTER TABLE notifications ADD INDEX idx_user_created (user_id, created_at DESC);

-- Standalone post_id index on likes (if not exists)
-- ALTER TABLE likes ADD INDEX idx_post_id (post_id);
-- NOTE: Already existed in production DB

-- following_id index on follows (if not exists)
-- ALTER TABLE follows ADD INDEX idx_following_id (following_id);
-- NOTE: Already existed in production DB

-- Add scheduled_at column to posts
ALTER TABLE posts ADD COLUMN scheduled_at DATETIME DEFAULT NULL AFTER updated_at;
ALTER TABLE posts ADD INDEX idx_scheduled (scheduled_at);
