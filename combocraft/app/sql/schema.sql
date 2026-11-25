-- ComboCraft tables (SQLite, local and tests).
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
  last_seen DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS cc_elements (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
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
  created_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS cc_recipes (
  a_id INT NOT NULL,
  b_id INT NOT NULL,
  result_id INT NOT NULL,
  source VARCHAR(12) NOT NULL,
  created_by VARCHAR(24) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (a_id, b_id)
);

CREATE TABLE IF NOT EXISTS cc_collection (
  player_id VARCHAR(24) NOT NULL,
  element_id INT NOT NULL,
  via_a INT NULL,
  via_b INT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (player_id, element_id)
);

CREATE TABLE IF NOT EXISTS cc_chat (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  player_id VARCHAR(24) NOT NULL,
  body VARCHAR(240) NOT NULL DEFAULT '',
  element_id INT NULL,
  created_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS cc_reactions (
  message_id INT NOT NULL,
  player_id VARCHAR(24) NOT NULL,
  emoji VARCHAR(16) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (message_id, player_id, emoji)
);

CREATE TABLE IF NOT EXISTS cc_kv (
  k VARCHAR(64) NOT NULL PRIMARY KEY,
  v VARCHAR(255) NOT NULL,
  updated_at DATETIME NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS cc_players_token ON cc_players (token_hash);
CREATE UNIQUE INDEX IF NOT EXISTS cc_players_nick ON cc_players (nick_key);
CREATE UNIQUE INDEX IF NOT EXISTS cc_elements_key ON cc_elements (name_key);
CREATE INDEX IF NOT EXISTS cc_elements_disc ON cc_elements (discovered_by);
CREATE INDEX IF NOT EXISTS cc_elements_at ON cc_elements (discovered_at);
CREATE INDEX IF NOT EXISTS cc_recipes_result ON cc_recipes (result_id);
CREATE INDEX IF NOT EXISTS cc_chat_at ON cc_chat (created_at);
