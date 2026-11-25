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

-- Viral features (additive cc_* tables only)
CREATE TABLE IF NOT EXISTS cc_daily (
  player_id VARCHAR(24) NOT NULL,
  day CHAR(10) NOT NULL,
  target_id INT NOT NULL,
  solved TINYINT NOT NULL DEFAULT 0,
  combos INT NOT NULL DEFAULT 0,
  path TEXT NULL,
  solved_at DATETIME NULL,
  PRIMARY KEY (player_id, day),
  KEY cc_daily_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_daily_stats (
  player_id VARCHAR(24) NOT NULL PRIMARY KEY,
  streak INT NOT NULL DEFAULT 0,
  best_streak INT NOT NULL DEFAULT 0,
  last_day CHAR(10) NOT NULL DEFAULT '',
  wins INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_achievements (
  player_id VARCHAR(24) NOT NULL,
  badge VARCHAR(32) NOT NULL,
  unlocked_at DATETIME NOT NULL,
  PRIMARY KEY (player_id, badge)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_hints (
  player_id VARCHAR(24) NOT NULL,
  day CHAR(10) NOT NULL,
  used INT NOT NULL DEFAULT 0,
  PRIMARY KEY (player_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_speedruns (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  player_id VARCHAR(24) NOT NULL,
  target_id INT NOT NULL,
  combos INT NOT NULL,
  ms INT NOT NULL,
  created_at DATETIME NOT NULL,
  KEY cc_speedruns_best (target_id, combos, ms),
  KEY cc_speedruns_player (player_id, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_speedrun_live (
  id VARCHAR(24) NOT NULL PRIMARY KEY,
  player_id VARCHAR(24) NOT NULL,
  target_id INT NOT NULL,
  started_at DATETIME NOT NULL,
  combos INT NOT NULL DEFAULT 0,
  inv TEXT NOT NULL,
  done TINYINT NOT NULL DEFAULT 0,
  KEY cc_speedrun_live_player (player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_referrals (
  player_id VARCHAR(24) NOT NULL PRIMARY KEY,
  code CHAR(8) NOT NULL,
  referred_by VARCHAR(24) NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY cc_referrals_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_morse_links (
  player_id VARCHAR(24) NOT NULL PRIMARY KEY,
  morse_user_id VARCHAR(36) NOT NULL,
  morse_name VARCHAR(64) NOT NULL DEFAULT '',
  linked_at DATETIME NOT NULL,
  UNIQUE KEY cc_morse_links_user (morse_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_coin_grants (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  player_id VARCHAR(24) NOT NULL,
  morse_user_id VARCHAR(36) NOT NULL,
  element_id INT NOT NULL,
  amount INT NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY cc_coin_grants_once (morse_user_id, element_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_clash_queue (
  player_id VARCHAR(24) NOT NULL PRIMARY KEY,
  queued_at DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  pair VARCHAR(32) NOT NULL DEFAULT '',
  KEY cc_clash_q_pair (pair, queued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_clash_challenges (
  id VARCHAR(24) NOT NULL PRIMARY KEY,
  from_id VARCHAR(24) NOT NULL,
  to_id VARCHAR(24) NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  status VARCHAR(16) NOT NULL,
  match_id VARCHAR(24) NULL,
  KEY cc_clash_ch_to (to_id, status),
  KEY cc_clash_ch_from (from_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_clash_matches (
  id VARCHAR(24) NOT NULL PRIMARY KEY,
  a_id VARCHAR(24) NOT NULL,
  b_id VARCHAR(24) NOT NULL,
  status VARCHAR(16) NOT NULL,
  round TINYINT NOT NULL DEFAULT 0,
  a_wins TINYINT NOT NULL DEFAULT 0,
  b_wins TINYINT NOT NULL DEFAULT 0,
  winner_id VARCHAR(24) NULL,
  forfeit_id VARCHAR(24) NULL,
  inter_until DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY cc_clash_m_live (status, a_id),
  KEY cc_clash_m_live_b (status, b_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_clash_loadouts (
  match_id VARCHAR(24) NOT NULL,
  player_id VARCHAR(24) NOT NULL,
  ids TEXT NOT NULL,
  ready TINYINT NOT NULL DEFAULT 0,
  last_play_ms BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (match_id, player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_clash_rounds (
  id VARCHAR(24) NOT NULL PRIMARY KEY,
  match_id VARCHAR(24) NOT NULL,
  n TINYINT NOT NULL,
  theme VARCHAR(180) NOT NULL,
  started_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  a_score INT NOT NULL DEFAULT 0,
  b_score INT NOT NULL DEFAULT 0,
  a_latest TEXT NULL,
  b_latest TEXT NULL,
  winner_id VARCHAR(24) NULL,
  settled TINYINT NOT NULL DEFAULT 0,
  KEY cc_clash_r_match (match_id, n)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_clash_plays (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
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
  score INT NOT NULL,
  breakdown TEXT NOT NULL,
  is_attack TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY cc_clash_p_round (round_id, player_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_clash_seen (
  player_id VARCHAR(24) NOT NULL PRIMARY KEY,
  last_poll DATETIME NOT NULL,
  match_id VARCHAR(24) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_clash_daily (
  day CHAR(10) NOT NULL,
  player_id VARCHAR(24) NOT NULL,
  wins INT NOT NULL DEFAULT 0,
  points INT NOT NULL DEFAULT 0,
  PRIMARY KEY (day, player_id),
  KEY cc_clash_daily_pts (day, points)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_clash_parties (
  id VARCHAR(24) NOT NULL PRIMARY KEY,
  name VARCHAR(24) NOT NULL DEFAULT '',
  host_id VARCHAR(24) NOT NULL,
  status VARCHAR(16) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY cc_clash_parties_host (host_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_clash_party_members (
  party_id VARCHAR(24) NOT NULL,
  player_id VARCHAR(24) NOT NULL,
  role VARCHAR(12) NOT NULL,
  joined_at DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  PRIMARY KEY (party_id, player_id),
  KEY cc_clash_pm_player (player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_clash_party_invites (
  id VARCHAR(24) NOT NULL PRIMARY KEY,
  party_id VARCHAR(24) NOT NULL,
  from_id VARCHAR(24) NOT NULL,
  to_id VARCHAR(24) NOT NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  status VARCHAR(16) NOT NULL,
  KEY cc_clash_pi_to (to_id, status),
  KEY cc_clash_pi_party (party_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_clash_party_queue (
  party_id VARCHAR(24) NOT NULL PRIMARY KEY,
  size TINYINT NOT NULL,
  pair VARCHAR(32) NOT NULL DEFAULT '',
  queued_at DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  KEY cc_clash_pq_pair (pair, size, queued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_clash_party_matches (
  id VARCHAR(24) NOT NULL PRIMARY KEY,
  a_party VARCHAR(24) NOT NULL,
  b_party VARCHAR(24) NOT NULL,
  a_name VARCHAR(24) NOT NULL,
  b_name VARCHAR(24) NOT NULL,
  status VARCHAR(16) NOT NULL,
  session TINYINT NOT NULL DEFAULT 0,
  target_name VARCHAR(80) NOT NULL DEFAULT '',
  target_emoji VARCHAR(32) NOT NULL DEFAULT '',
  a_session TINYINT NOT NULL DEFAULT 0,
  b_session TINYINT NOT NULL DEFAULT 0,
  a_alive TINYINT NOT NULL DEFAULT 1,
  b_alive TINYINT NOT NULL DEFAULT 1,
  a_ends_at DATETIME NULL,
  b_ends_at DATETIME NULL,
  intro_until DATETIME NULL,
  winner_party VARCHAR(24) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY cc_clash_pam_live (status, a_party),
  KEY cc_clash_pam_live_b (status, b_party)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_clash_party_plays (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  match_id VARCHAR(24) NOT NULL,
  session TINYINT NOT NULL,
  party_id VARCHAR(24) NOT NULL,
  player_id VARCHAR(24) NOT NULL,
  a_name VARCHAR(80) NOT NULL,
  b_name VARCHAR(80) NOT NULL,
  result_name VARCHAR(80) NOT NULL,
  result_emoji VARCHAR(32) NOT NULL DEFAULT '',
  result_tagline VARCHAR(160) NOT NULL DEFAULT '',
  rarity VARCHAR(16) NOT NULL DEFAULT 'Common',
  is_hit TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY cc_clash_pp_match (match_id, session, party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_clash_party_chat (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  party_id VARCHAR(24) NOT NULL,
  match_id VARCHAR(24) NULL,
  player_id VARCHAR(24) NOT NULL,
  body VARCHAR(240) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY cc_clash_pc_party (party_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_clash_replays (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(12) NOT NULL,
  match_id VARCHAR(24) NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  KEY cc_clash_rep_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_clash_rtc_peers (
  room VARCHAR(64) NOT NULL,
  peer_id VARCHAR(32) NOT NULL,
  name VARCHAR(64) NOT NULL DEFAULT '',
  last_seen DATETIME NOT NULL,
  PRIMARY KEY (room, peer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_clash_rtc_signals (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  room VARCHAR(64) NOT NULL,
  to_peer VARCHAR(32) NOT NULL,
  from_peer VARCHAR(32) NOT NULL,
  kind VARCHAR(12) NOT NULL,
  payload TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  KEY cc_clash_rtc_inbox (room, to_peer, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_clash_ranks (
  player_id VARCHAR(24) NOT NULL PRIMARY KEY,
  mmr INT NOT NULL DEFAULT 600,
  xp INT NOT NULL DEFAULT 0,
  level INT NOT NULL DEFAULT 1,
  wins INT NOT NULL DEFAULT 0,
  losses INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_clash_quick (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  match_id VARCHAR(24) NOT NULL,
  player_id VARCHAR(24) NOT NULL,
  phrase VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY cc_clash_qck_match (match_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_daily_chain (
  player_id VARCHAR(24) NOT NULL,
  day CHAR(10) NOT NULL,
  level INT NOT NULL DEFAULT 1,
  score INT NOT NULL DEFAULT 0,
  solved_keys TEXT NOT NULL,
  target_key VARCHAR(80) NOT NULL DEFAULT '',
  target_name VARCHAR(160) NOT NULL DEFAULT '',
  steps INT NOT NULL DEFAULT 1,
  combos INT NOT NULL DEFAULT 0,
  path TEXT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (player_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_prefs (
  player_id VARCHAR(24) NOT NULL PRIMARY KEY,
  bg VARCHAR(32) NOT NULL DEFAULT 'stadium',
  file VARCHAR(48) NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_clash_party_play_scores (
  play_id INT NOT NULL PRIMARY KEY,
  match_id VARCHAR(24) NOT NULL,
  player_id VARCHAR(24) NOT NULL,
  score INT NOT NULL,
  closeness DECIMAL(6,4) NOT NULL,
  heat VARCHAR(16) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY cc_clash_pps_match (match_id, player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
