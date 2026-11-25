-- ComboCraft tables (prefix cc_), additive only, never touches other apps.
CREATE TABLE IF NOT EXISTS cc_players (
  id VARCHAR(24) NOT NULL PRIMARY KEY,
  token_hash CHAR(64) NOT NULL,
  nickname VARCHAR(24) NULL,
  nick_key VARCHAR(24) NULL,
  avatar VARCHAR(16) NULL,
  day CHAR(10) NOT NULL DEFAULT '',
  day_count INT NOT NULL DEFAULT 0,
  streak INT NOT NULL DEFAULT 0,
  last_day CHAR(10) NOT NULL DEFAULT '',
  firsts INT NOT NULL DEFAULT 0,
  combos INT NOT NULL DEFAULT 0,
  ip_hash CHAR(16) NULL,
  created_at DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  UNIQUE KEY cc_players_token (token_hash),
  UNIQUE KEY cc_players_nick (nick_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_elements (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL,
  name_key VARCHAR(64) NOT NULL,
  emoji VARCHAR(32) NOT NULL,
  tagline VARCHAR(120) NULL,
  art VARCHAR(32) NULL,
  label VARCHAR(16) NULL,
  depth INT NOT NULL DEFAULT 0,
  is_base TINYINT NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  discovered_by VARCHAR(24) NULL,
  discovered_at DATETIME NULL,
  first_a INT NULL,
  first_b INT NULL,
  uses INT NOT NULL DEFAULT 0,
  source VARCHAR(12) NOT NULL DEFAULT 'seed',
  created_at DATETIME NOT NULL,
  UNIQUE KEY cc_elements_key (name_key),
  KEY cc_elements_disc (discovered_by),
  KEY cc_elements_at (discovered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_recipes (
  a_id INT NOT NULL,
  b_id INT NOT NULL,
  result_id INT NOT NULL,
  source VARCHAR(12) NOT NULL,
  created_by VARCHAR(24) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (a_id, b_id),
  KEY cc_recipes_result (result_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_collection (
  player_id VARCHAR(24) NOT NULL,
  element_id INT NOT NULL,
  via_a INT NULL,
  via_b INT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (player_id, element_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_chat (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  player_id VARCHAR(24) NOT NULL,
  body VARCHAR(240) NOT NULL DEFAULT '',
  element_id INT NULL,
  created_at DATETIME NOT NULL,
  KEY cc_chat_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_reactions (
  message_id INT NOT NULL,
  player_id VARCHAR(24) NOT NULL,
  emoji VARCHAR(16) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (message_id, player_id, emoji)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_kv (
  k VARCHAR(64) NOT NULL PRIMARY KEY,
  v VARCHAR(255) NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
