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

-- Viral features (additive cc_* tables only)
CREATE TABLE IF NOT EXISTS cc_daily (
  player_id VARCHAR(24) NOT NULL,
  day CHAR(10) NOT NULL,
  target_id INT NOT NULL,
  solved TINYINT NOT NULL DEFAULT 0,
  combos INT NOT NULL DEFAULT 0,
  path TEXT NULL,
  solved_at DATETIME NULL,
  PRIMARY KEY (player_id, day)
);
CREATE INDEX IF NOT EXISTS cc_daily_day ON cc_daily (day);

CREATE TABLE IF NOT EXISTS cc_daily_stats (
  player_id VARCHAR(24) NOT NULL PRIMARY KEY,
  streak INT NOT NULL DEFAULT 0,
  best_streak INT NOT NULL DEFAULT 0,
  last_day CHAR(10) NOT NULL DEFAULT '',
  wins INT NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS cc_achievements (
  player_id VARCHAR(24) NOT NULL,
  badge VARCHAR(32) NOT NULL,
  unlocked_at DATETIME NOT NULL,
  PRIMARY KEY (player_id, badge)
);

CREATE TABLE IF NOT EXISTS cc_hints (
  player_id VARCHAR(24) NOT NULL,
  day CHAR(10) NOT NULL,
  used INT NOT NULL DEFAULT 0,
  PRIMARY KEY (player_id, day)
);

CREATE TABLE IF NOT EXISTS cc_speedruns (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  player_id VARCHAR(24) NOT NULL,
  target_id INT NOT NULL,
  combos INT NOT NULL,
  ms INT NOT NULL,
  created_at DATETIME NOT NULL
);
CREATE INDEX IF NOT EXISTS cc_speedruns_best ON cc_speedruns (target_id, combos, ms);
CREATE INDEX IF NOT EXISTS cc_speedruns_player ON cc_speedruns (player_id, target_id);

CREATE TABLE IF NOT EXISTS cc_speedrun_live (
  id VARCHAR(24) NOT NULL PRIMARY KEY,
  player_id VARCHAR(24) NOT NULL,
  target_id INT NOT NULL,
  started_at DATETIME NOT NULL,
  combos INT NOT NULL DEFAULT 0,
  inv TEXT NOT NULL,
  done TINYINT NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS cc_speedrun_live_player ON cc_speedrun_live (player_id);

CREATE TABLE IF NOT EXISTS cc_referrals (
  player_id VARCHAR(24) NOT NULL PRIMARY KEY,
  code CHAR(8) NOT NULL,
  referred_by VARCHAR(24) NULL,
  created_at DATETIME NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS cc_referrals_code ON cc_referrals (code);

CREATE TABLE IF NOT EXISTS cc_morse_links (
  player_id VARCHAR(24) NOT NULL PRIMARY KEY,
  morse_user_id VARCHAR(36) NOT NULL,
  morse_name VARCHAR(64) NOT NULL DEFAULT '',
  linked_at DATETIME NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS cc_morse_links_user ON cc_morse_links (morse_user_id);

CREATE TABLE IF NOT EXISTS cc_coin_grants (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  player_id VARCHAR(24) NOT NULL,
  morse_user_id VARCHAR(36) NOT NULL,
  element_id INT NOT NULL,
  amount INT NOT NULL,
  created_at DATETIME NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS cc_coin_grants_once ON cc_coin_grants (morse_user_id, element_id);

CREATE TABLE IF NOT EXISTS cc_clash_queue (
  player_id VARCHAR(24) NOT NULL PRIMARY KEY,
  queued_at DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  pair VARCHAR(32) NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS cc_clash_q_pair ON cc_clash_queue (pair, queued_at);

CREATE TABLE IF NOT EXISTS cc_clash_challenges (
  id VARCHAR(24) NOT NULL PRIMARY KEY,
  from_id VARCHAR(24) NOT NULL,
  to_id VARCHAR(24) NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  status VARCHAR(16) NOT NULL,
  match_id VARCHAR(24) NULL
);
CREATE INDEX IF NOT EXISTS cc_clash_ch_to ON cc_clash_challenges (to_id, status);
CREATE INDEX IF NOT EXISTS cc_clash_ch_from ON cc_clash_challenges (from_id, status);

CREATE TABLE IF NOT EXISTS cc_clash_matches (
  id VARCHAR(24) NOT NULL PRIMARY KEY,
  a_id VARCHAR(24) NOT NULL,
  b_id VARCHAR(24) NOT NULL,
  status VARCHAR(16) NOT NULL,
  round INTEGER NOT NULL DEFAULT 0,
  a_wins INTEGER NOT NULL DEFAULT 0,
  b_wins INTEGER NOT NULL DEFAULT 0,
  winner_id VARCHAR(24) NULL,
  forfeit_id VARCHAR(24) NULL,
  inter_until DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
);
CREATE INDEX IF NOT EXISTS cc_clash_m_live ON cc_clash_matches (status, a_id);
CREATE INDEX IF NOT EXISTS cc_clash_m_live_b ON cc_clash_matches (status, b_id);

CREATE TABLE IF NOT EXISTS cc_clash_loadouts (
  match_id VARCHAR(24) NOT NULL,
  player_id VARCHAR(24) NOT NULL,
  ids TEXT NOT NULL,
  ready INTEGER NOT NULL DEFAULT 0,
  last_play_ms INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (match_id, player_id)
);

CREATE TABLE IF NOT EXISTS cc_clash_rounds (
  id VARCHAR(24) NOT NULL PRIMARY KEY,
  match_id VARCHAR(24) NOT NULL,
  n INTEGER NOT NULL,
  theme VARCHAR(180) NOT NULL,
  started_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  a_score INTEGER NOT NULL DEFAULT 0,
  b_score INTEGER NOT NULL DEFAULT 0,
  a_latest TEXT NULL,
  b_latest TEXT NULL,
  winner_id VARCHAR(24) NULL,
  settled INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS cc_clash_r_match ON cc_clash_rounds (match_id, n);

CREATE TABLE IF NOT EXISTS cc_clash_plays (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  round_id VARCHAR(24) NOT NULL,
  player_id VARCHAR(24) NOT NULL,
  a_name VARCHAR(80) NOT NULL,
  b_name VARCHAR(80) NOT NULL,
  a_emoji VARCHAR(32) NOT NULL DEFAULT '',
  b_emoji VARCHAR(32) NOT NULL DEFAULT '',
  result_name VARCHAR(80) NOT NULL,
  result_emoji VARCHAR(32) NOT NULL DEFAULT '',
  result_tagline VARCHAR(160) NOT NULL DEFAULT '',
  rarity VARCHAR(16) NOT NULL,
  score INTEGER NOT NULL,
  breakdown TEXT NOT NULL,
  is_attack INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL
);
CREATE INDEX IF NOT EXISTS cc_clash_p_round ON cc_clash_plays (round_id, player_id, id);

CREATE TABLE IF NOT EXISTS cc_clash_seen (
  player_id VARCHAR(24) NOT NULL PRIMARY KEY,
  last_poll DATETIME NOT NULL,
  match_id VARCHAR(24) NULL
);

CREATE TABLE IF NOT EXISTS cc_clash_daily (
  day CHAR(10) NOT NULL,
  player_id VARCHAR(24) NOT NULL,
  wins INTEGER NOT NULL DEFAULT 0,
  points INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (day, player_id)
);
CREATE INDEX IF NOT EXISTS cc_clash_daily_pts ON cc_clash_daily (day, points);

CREATE TABLE IF NOT EXISTS cc_clash_parties (
  id VARCHAR(24) NOT NULL PRIMARY KEY,
  name VARCHAR(24) NOT NULL DEFAULT '',
  host_id VARCHAR(24) NOT NULL,
  status VARCHAR(16) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
);
CREATE INDEX IF NOT EXISTS cc_clash_parties_host ON cc_clash_parties (host_id, status);

CREATE TABLE IF NOT EXISTS cc_clash_party_members (
  party_id VARCHAR(24) NOT NULL,
  player_id VARCHAR(24) NOT NULL,
  role VARCHAR(12) NOT NULL,
  joined_at DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  PRIMARY KEY (party_id, player_id)
);
CREATE INDEX IF NOT EXISTS cc_clash_pm_player ON cc_clash_party_members (player_id);

CREATE TABLE IF NOT EXISTS cc_clash_party_invites (
  id VARCHAR(24) NOT NULL PRIMARY KEY,
  party_id VARCHAR(24) NOT NULL,
  from_id VARCHAR(24) NOT NULL,
  to_id VARCHAR(24) NOT NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  status VARCHAR(16) NOT NULL
);
CREATE INDEX IF NOT EXISTS cc_clash_pi_to ON cc_clash_party_invites (to_id, status);
CREATE INDEX IF NOT EXISTS cc_clash_pi_party ON cc_clash_party_invites (party_id, status);

CREATE TABLE IF NOT EXISTS cc_clash_party_queue (
  party_id VARCHAR(24) NOT NULL PRIMARY KEY,
  size INTEGER NOT NULL,
  pair VARCHAR(32) NOT NULL DEFAULT '',
  queued_at DATETIME NOT NULL,
  last_seen DATETIME NOT NULL
);
CREATE INDEX IF NOT EXISTS cc_clash_pq_pair ON cc_clash_party_queue (pair, size, queued_at);

CREATE TABLE IF NOT EXISTS cc_clash_party_matches (
  id VARCHAR(24) NOT NULL PRIMARY KEY,
  a_party VARCHAR(24) NOT NULL,
  b_party VARCHAR(24) NOT NULL,
  a_name VARCHAR(24) NOT NULL,
  b_name VARCHAR(24) NOT NULL,
  status VARCHAR(16) NOT NULL,
  session INTEGER NOT NULL DEFAULT 0,
  target_name VARCHAR(80) NOT NULL DEFAULT '',
  target_emoji VARCHAR(32) NOT NULL DEFAULT '',
  a_session INTEGER NOT NULL DEFAULT 0,
  b_session INTEGER NOT NULL DEFAULT 0,
  a_alive INTEGER NOT NULL DEFAULT 1,
  b_alive INTEGER NOT NULL DEFAULT 1,
  a_ends_at DATETIME NULL,
  b_ends_at DATETIME NULL,
  intro_until DATETIME NULL,
  winner_party VARCHAR(24) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
);
CREATE INDEX IF NOT EXISTS cc_clash_pam_live ON cc_clash_party_matches (status, a_party);
CREATE INDEX IF NOT EXISTS cc_clash_pam_live_b ON cc_clash_party_matches (status, b_party);

CREATE TABLE IF NOT EXISTS cc_clash_party_plays (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  match_id VARCHAR(24) NOT NULL,
  session INTEGER NOT NULL,
  party_id VARCHAR(24) NOT NULL,
  player_id VARCHAR(24) NOT NULL,
  a_name VARCHAR(80) NOT NULL,
  b_name VARCHAR(80) NOT NULL,
  result_name VARCHAR(80) NOT NULL,
  result_emoji VARCHAR(32) NOT NULL DEFAULT '',
  result_tagline VARCHAR(160) NOT NULL DEFAULT '',
  rarity VARCHAR(16) NOT NULL DEFAULT 'Common',
  is_hit INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL
);
CREATE INDEX IF NOT EXISTS cc_clash_pp_match ON cc_clash_party_plays (match_id, session, party_id);

CREATE TABLE IF NOT EXISTS cc_clash_party_chat (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  party_id VARCHAR(24) NOT NULL,
  match_id VARCHAR(24) NULL,
  player_id VARCHAR(24) NOT NULL,
  body VARCHAR(240) NOT NULL,
  created_at DATETIME NOT NULL
);
CREATE INDEX IF NOT EXISTS cc_clash_pc_party ON cc_clash_party_chat (party_id, id);

CREATE TABLE IF NOT EXISTS cc_clash_replays (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kind VARCHAR(12) NOT NULL,
  match_id VARCHAR(24) NOT NULL,
  payload TEXT NOT NULL,
  created_at DATETIME NOT NULL
);
CREATE INDEX IF NOT EXISTS cc_clash_rep_created ON cc_clash_replays (created_at);

CREATE TABLE IF NOT EXISTS cc_clash_rtc_peers (
  room VARCHAR(64) NOT NULL,
  peer_id VARCHAR(32) NOT NULL,
  name VARCHAR(64) NOT NULL DEFAULT '',
  last_seen DATETIME NOT NULL,
  PRIMARY KEY (room, peer_id)
);

CREATE TABLE IF NOT EXISTS cc_clash_rtc_signals (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  room VARCHAR(64) NOT NULL,
  to_peer VARCHAR(32) NOT NULL,
  from_peer VARCHAR(32) NOT NULL,
  kind VARCHAR(12) NOT NULL,
  payload TEXT NOT NULL,
  created_at DATETIME NOT NULL
);
CREATE INDEX IF NOT EXISTS cc_clash_rtc_inbox ON cc_clash_rtc_signals (room, to_peer, id);
