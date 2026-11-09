-- MySQL / MariaDB schema for World Domination (wd_* tables only).
-- Indexed columns are VARCHAR (MySQL cannot index TEXT without a prefix,
-- error 1170). Lengths stay within the 767-byte utf8mb4 index limit of
-- older MySQL/MariaDB. Timestamps are stored as 'Y-m-d H:i:s' strings, the
-- same format the SQLite build uses.
CREATE TABLE IF NOT EXISTS wd_users (
  id VARCHAR(64) NOT NULL,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at VARCHAR(32) NOT NULL,
  updated_at VARCHAR(32) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY wd_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wd_sessions (
  id VARCHAR(64) NOT NULL,
  user_id VARCHAR(64) NOT NULL,
  token_hash VARCHAR(128) NOT NULL,
  expires_at VARCHAR(32) NOT NULL,
  created_at VARCHAR(32) NOT NULL,
  updated_at VARCHAR(32) NOT NULL,
  ip VARCHAR(64) NULL,
  user_agent TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY wd_sessions_token (token_hash),
  KEY wd_sessions_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wd_throttle (
  bucket VARCHAR(190) NOT NULL,
  hits INT NOT NULL,
  window_start VARCHAR(32) NOT NULL,
  PRIMARY KEY (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wd_matches (
  id VARCHAR(64) NOT NULL,
  user_id VARCHAR(64) NOT NULL,
  country_code VARCHAR(16) NOT NULL,
  token_hash VARCHAR(128) NOT NULL,
  started_at VARCHAR(32) NOT NULL,
  finished_at VARCHAR(32) NULL,
  duration_ms INT NULL,
  player_pct DOUBLE NULL,
  player_score INT NULL,
  outcome VARCHAR(16) NULL,
  bots_json TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY wd_matches_token (token_hash),
  KEY wd_matches_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wd_scores (
  id VARCHAR(64) NOT NULL,
  subject_key VARCHAR(190) NOT NULL,
  display_name VARCHAR(100) NOT NULL,
  is_bot TINYINT NOT NULL DEFAULT 0,
  points INT NOT NULL DEFAULT 0,
  wins INT NOT NULL DEFAULT 0,
  matches INT NOT NULL DEFAULT 0,
  best_pct DOUBLE NOT NULL DEFAULT 0,
  updated_at VARCHAR(32) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY wd_scores_subject (subject_key),
  KEY wd_scores_points (is_bot, points)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
