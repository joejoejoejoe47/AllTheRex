CREATE TABLE IF NOT EXISTS wd_users (
  id TEXT NOT NULL,
  name TEXT NOT NULL,
  email TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE (email)
);

CREATE TABLE IF NOT EXISTS wd_sessions (
  id TEXT NOT NULL,
  user_id TEXT NOT NULL,
  token_hash TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  ip TEXT,
  user_agent TEXT,
  PRIMARY KEY (id),
  UNIQUE (token_hash)
);

CREATE TABLE IF NOT EXISTS wd_throttle (
  bucket TEXT NOT NULL,
  hits INTEGER NOT NULL,
  window_start TEXT NOT NULL,
  PRIMARY KEY (bucket)
);

CREATE TABLE IF NOT EXISTS wd_matches (
  id TEXT NOT NULL,
  user_id TEXT NOT NULL,
  country_code TEXT NOT NULL,
  token_hash TEXT NOT NULL,
  started_at TEXT NOT NULL,
  finished_at TEXT,
  duration_ms INTEGER,
  player_pct REAL,
  player_score INTEGER,
  outcome TEXT,
  bots_json TEXT,
  PRIMARY KEY (id),
  UNIQUE (token_hash)
);

CREATE TABLE IF NOT EXISTS wd_scores (
  id TEXT NOT NULL,
  subject_key TEXT NOT NULL,
  display_name TEXT NOT NULL,
  is_bot INTEGER NOT NULL DEFAULT 0,
  points INTEGER NOT NULL DEFAULT 0,
  wins INTEGER NOT NULL DEFAULT 0,
  matches INTEGER NOT NULL DEFAULT 0,
  best_pct REAL NOT NULL DEFAULT 0,
  updated_at TEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE (subject_key)
);
