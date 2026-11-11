-- MySQL / MariaDB schema for Saint Quest (sq_* tables only).
-- Indexed columns are VARCHAR within the 767-byte utf8mb4 index limit.
-- Timestamps are 'Y-m-d H:i:s' UTC strings, same as the SQLite build.
CREATE TABLE IF NOT EXISTS sq_users (
  id VARCHAR(32) NOT NULL,
  username VARCHAR(40) NOT NULL,
  username_lc VARCHAR(40) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  virtue INT NOT NULL DEFAULT 0,
  lifetime INT NOT NULL DEFAULT 0,
  good_deeds INT NOT NULL DEFAULT 0,
  created_at VARCHAR(32) NOT NULL,
  updated_at VARCHAR(32) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY sq_users_name (username_lc),
  KEY sq_users_lifetime (lifetime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sq_sessions (
  id VARCHAR(32) NOT NULL,
  user_id VARCHAR(32) NOT NULL,
  token_hash VARCHAR(64) NOT NULL,
  expires_at VARCHAR(32) NOT NULL,
  created_at VARCHAR(32) NOT NULL,
  updated_at VARCHAR(32) NOT NULL,
  ip VARCHAR(64) NULL,
  user_agent TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY sq_sessions_token (token_hash),
  KEY sq_sessions_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sq_throttle (
  bucket VARCHAR(190) NOT NULL,
  hits INT NOT NULL,
  window_start VARCHAR(32) NOT NULL,
  PRIMARY KEY (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sq_saints (
  id VARCHAR(32) NOT NULL,
  owner_id VARCHAR(32) NOT NULL,
  saint_key VARCHAR(40) NOT NULL,
  rating INT NOT NULL DEFAULT 1000,
  wins INT NOT NULL DEFAULT 0,
  losses INT NOT NULL DEFAULT 0,
  source VARCHAR(16) NOT NULL,
  card_id VARCHAR(32) NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'ok',
  created_at VARCHAR(32) NOT NULL,
  updated_at VARCHAR(32) NOT NULL,
  PRIMARY KEY (id),
  KEY sq_saints_owner (owner_id),
  KEY sq_saints_card (card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sq_listings (
  id VARCHAR(32) NOT NULL,
  saint_id VARCHAR(32) NOT NULL,
  seller_id VARCHAR(32) NOT NULL,
  price INT NOT NULL,
  benefit TEXT NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'open',
  buyer_id VARCHAR(32) NULL,
  created_at VARCHAR(32) NOT NULL,
  closed_at VARCHAR(32) NULL,
  PRIMARY KEY (id),
  KEY sq_listings_status (status, created_at),
  KEY sq_listings_saint (saint_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sq_images (
  id VARCHAR(32) NOT NULL,
  owner_id VARCHAR(32) NOT NULL,
  kind VARCHAR(16) NOT NULL,
  file VARCHAR(80) NOT NULL,
  mime VARCHAR(32) NOT NULL,
  width INT NOT NULL,
  height INT NOT NULL,
  bytes INT NOT NULL,
  created_at VARCHAR(32) NOT NULL,
  PRIMARY KEY (id),
  KEY sq_images_owner (owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sq_comments (
  id VARCHAR(32) NOT NULL,
  saint_id VARCHAR(32) NOT NULL,
  author_id VARCHAR(32) NOT NULL,
  body TEXT NOT NULL,
  image_id VARCHAR(32) NULL,
  hidden TINYINT NOT NULL DEFAULT 0,
  reports INT NOT NULL DEFAULT 0,
  created_at VARCHAR(32) NOT NULL,
  PRIMARY KEY (id),
  KEY sq_comments_saint (saint_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sq_comment_reports (
  comment_id VARCHAR(32) NOT NULL,
  reporter_id VARCHAR(32) NOT NULL,
  created_at VARCHAR(32) NOT NULL,
  PRIMARY KEY (comment_id, reporter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sq_cards (
  id VARCHAR(32) NOT NULL,
  code VARCHAR(24) NOT NULL,
  saint_key VARCHAR(40) NOT NULL,
  printed_by VARCHAR(32) NOT NULL,
  owner_id VARCHAR(32) NULL,
  saint_id VARCHAR(32) NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'new',
  created_at VARCHAR(32) NOT NULL,
  claimed_at VARCHAR(32) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY sq_cards_code (code),
  KEY sq_cards_printed (printed_by, created_at),
  KEY sq_cards_owner (owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sq_card_claims (
  id VARCHAR(32) NOT NULL,
  card_id VARCHAR(32) NOT NULL,
  claimant_id VARCHAR(32) NOT NULL,
  owner_id VARCHAR(32) NOT NULL,
  status VARCHAR(20) NOT NULL,
  owner_photo_id VARCHAR(32) NULL,
  claimant_photo_id VARCHAR(32) NULL,
  created_at VARCHAR(32) NOT NULL,
  updated_at VARCHAR(32) NOT NULL,
  PRIMARY KEY (id),
  KEY sq_claims_card (card_id, status),
  KEY sq_claims_owner (owner_id, status),
  KEY sq_claims_claimant (claimant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sq_notifications (
  id VARCHAR(32) NOT NULL,
  user_id VARCHAR(32) NOT NULL,
  kind VARCHAR(24) NOT NULL,
  title VARCHAR(160) NOT NULL,
  body TEXT NULL,
  ref_type VARCHAR(16) NULL,
  ref_id VARCHAR(32) NULL,
  seen TINYINT NOT NULL DEFAULT 0,
  created_at VARCHAR(32) NOT NULL,
  PRIMARY KEY (id),
  KEY sq_notes_user (user_id, seen, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sq_battles (
  id VARCHAR(32) NOT NULL,
  user_id VARCHAR(32) NOT NULL,
  saint_id VARCHAR(32) NOT NULL,
  game VARCHAR(16) NOT NULL,
  seed INT NOT NULL,
  token_hash VARCHAR(64) NOT NULL,
  started_at VARCHAR(32) NOT NULL,
  started_ms BIGINT NOT NULL,
  finished_at VARCHAR(32) NULL,
  score INT NULL,
  won TINYINT NULL,
  reward INT NULL,
  PRIMARY KEY (id),
  KEY sq_battles_user (user_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sq_deeds (
  id VARCHAR(32) NOT NULL,
  user_id VARCHAR(32) NOT NULL,
  day VARCHAR(10) NOT NULL,
  deed_key VARCHAR(24) NOT NULL,
  created_at VARCHAR(32) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY sq_deeds_once (user_id, day, deed_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sq_cases (
  id VARCHAR(32) NOT NULL,
  user_id VARCHAR(32) NOT NULL,
  day VARCHAR(10) NOT NULL,
  case_key VARCHAR(24) NOT NULL,
  reward INT NOT NULL DEFAULT 0,
  created_at VARCHAR(32) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY sq_cases_once (user_id, day, case_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sq_blessings (
  id VARCHAR(32) NOT NULL,
  user_id VARCHAR(32) NOT NULL,
  day VARCHAR(10) NOT NULL,
  amount INT NOT NULL,
  created_at VARCHAR(32) NOT NULL,
  paid_at VARCHAR(32) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY sq_blessings_once (user_id, day),
  KEY sq_blessings_user (user_id, paid_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
