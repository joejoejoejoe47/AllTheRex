<?php
declare(strict_types=1);

/** CREATE TABLE statements for MySQL (production) or SQLite (tests). */
function vchat_schema(string $driver): array
{
    $my = $driver === 'mysql';
    $tail = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $id = $my ? 'BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $idx = fn(string $name, string $table, string $cols) => $my ? '' : "CREATE INDEX IF NOT EXISTS $name ON $table ($cols)";
    $inline = fn(string $def) => $my ? ", $def" : '';

    $sql = [
        "CREATE TABLE IF NOT EXISTS vchat_users (
            id CHAR(36) NOT NULL PRIMARY KEY, google_sub VARCHAR(64) NULL UNIQUE, email VARCHAR(255) NULL,
            phone VARCHAR(20) NULL UNIQUE, name VARCHAR(60) NOT NULL, picture VARCHAR(512) NULL,
            totp_secret VARCHAR(64) NULL, totp_enabled TINYINT NOT NULL DEFAULT 0, totp_last_step BIGINT NOT NULL DEFAULT 0,
            recovery_hashes TEXT NOT NULL, created_at BIGINT NOT NULL)$tail",
        "CREATE TABLE IF NOT EXISTS vchat_otps (
            phone VARCHAR(20) NOT NULL PRIMARY KEY, hash CHAR(64) NOT NULL, expires_at BIGINT NOT NULL, attempts INT NOT NULL DEFAULT 0)$tail",
        "CREATE TABLE IF NOT EXISTS vchat_meetings (
            code VARCHAR(16) NOT NULL PRIMARY KEY, host_id CHAR(36) NOT NULL, title VARCHAR(120) NOT NULL,
            ended TINYINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL)$tail",
        "CREATE TABLE IF NOT EXISTS vchat_participants (
            peer_id VARCHAR(16) NOT NULL PRIMARY KEY, meeting_code VARCHAR(16) NOT NULL, user_id CHAR(36) NOT NULL,
            state TEXT NOT NULL, last_seen BIGINT NOT NULL, joined_at BIGINT NOT NULL" . $inline('INDEX idx_part_meeting (meeting_code)') . ")$tail",
        "CREATE TABLE IF NOT EXISTS vchat_events (
            id $id, meeting_code VARCHAR(16) NOT NULL, to_peer VARCHAR(16) NULL, from_peer VARCHAR(16) NOT NULL,
            type VARCHAR(16) NOT NULL, payload MEDIUMTEXT NOT NULL, created_at BIGINT NOT NULL" . $inline('INDEX idx_ev_meeting (meeting_code, id)') . ")$tail",
        "CREATE TABLE IF NOT EXISTS vchat_bans (
            meeting_code VARCHAR(16) NOT NULL, user_id CHAR(36) NOT NULL, PRIMARY KEY (meeting_code, user_id))$tail",
        "CREATE TABLE IF NOT EXISTS vchat_rate (k CHAR(40) NOT NULL PRIMARY KEY, n INT NOT NULL, reset_at BIGINT NOT NULL)$tail",
    ];
    if (!$my) {
        $sql[] = $idx('idx_part_meeting', 'vchat_participants', 'meeting_code');
        $sql[] = $idx('idx_ev_meeting', 'vchat_events', 'meeting_code, id');
    }
    return array_values(array_filter($sql));
}
