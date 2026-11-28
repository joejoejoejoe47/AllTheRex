-- Suggested MySQL schema matching the store interface (db/README.md).
-- Adjust types/names to your new database.

CREATE TABLE vchat_users (
  id               CHAR(36)     NOT NULL PRIMARY KEY,
  google_sub       VARCHAR(64)  NULL UNIQUE,
  email            VARCHAR(255) NULL,
  phone            VARCHAR(20)  NULL UNIQUE,         -- E.164, e.g. +15551234567
  name             VARCHAR(60)  NOT NULL,
  picture          VARCHAR(512) NULL,
  totp_secret      VARCHAR(64)  NULL,                -- encrypt at rest if you can
  totp_enabled     TINYINT(1)   NOT NULL DEFAULT 0,
  totp_last_step   BIGINT       NOT NULL DEFAULT 0,  -- replay protection
  recovery_hashes  JSON         NOT NULL,            -- array of sha256 hex strings
  created_at       BIGINT       NOT NULL             -- ms since epoch
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE vchat_otps (
  phone       VARCHAR(20) NOT NULL PRIMARY KEY,
  hash        CHAR(64)    NOT NULL,
  expires_at  BIGINT      NOT NULL,
  attempts    INT         NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE vchat_meetings (
  code        VARCHAR(16)  NOT NULL PRIMARY KEY,
  host_id     CHAR(36)     NOT NULL,
  title       VARCHAR(120) NOT NULL,
  ended       TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  BIGINT       NOT NULL,
  FOREIGN KEY (host_id) REFERENCES vchat_users(id)
) ENGINE=InnoDB;
