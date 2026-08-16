-- ============================================================================
--  ROYAL SPIN — SQLite schema (test suite / driver-free local run)
--  Mirrors database/schema.mysql.sql exactly in column names and semantics.
-- ============================================================================

CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT NOT NULL,
    username_key  TEXT NOT NULL UNIQUE,
    email         TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS user_stats (
    user_id             INTEGER PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
    games_played        INTEGER NOT NULL DEFAULT 0,
    wins                INTEGER NOT NULL DEFAULT 0,
    losses              INTEGER NOT NULL DEFAULT 0,
    total_spins         INTEGER NOT NULL DEFAULT 0,
    total_pairs         INTEGER NOT NULL DEFAULT 0,
    total_triples       INTEGER NOT NULL DEFAULT 0,
    crown_triples       INTEGER NOT NULL DEFAULT 0,
    trophy_triples      INTEGER NOT NULL DEFAULT 0,
    highest_match_score INTEGER NOT NULL DEFAULT 0,
    biggest_single_win  INTEGER NOT NULL DEFAULT 0,
    total_coins_won     INTEGER NOT NULL DEFAULT 0,
    updated_at          TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS rooms (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    code         TEXT NOT NULL UNIQUE,
    host_user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    max_players  INTEGER NOT NULL DEFAULT 4,
    target_coins INTEGER NOT NULL DEFAULT 1500,
    status       TEXT NOT NULL DEFAULT 'lobby',
    match_id     INTEGER NULL,
    created_at   TEXT NOT NULL,
    updated_at   TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS room_players (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    room_id   INTEGER NOT NULL REFERENCES rooms (id) ON DELETE CASCADE,
    user_id   INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    seat      INTEGER NOT NULL,
    is_host   INTEGER NOT NULL DEFAULT 0,
    is_ready  INTEGER NOT NULL DEFAULT 0,
    joined_at TEXT NOT NULL,
    UNIQUE (room_id, user_id),
    UNIQUE (room_id, seat)
);

CREATE TABLE IF NOT EXISTS matches (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    room_code       TEXT NOT NULL,
    status          TEXT NOT NULL DEFAULT 'active',
    phase           TEXT NOT NULL DEFAULT 'waiting_for_dice',
    target_coins    INTEGER NOT NULL,
    starting_coins  INTEGER NOT NULL,
    player_count    INTEGER NOT NULL,
    current_seat    INTEGER NOT NULL DEFAULT 0,
    turn_number     INTEGER NOT NULL DEFAULT 1,
    round_number    INTEGER NOT NULL DEFAULT 1,
    winner_user_id  INTEGER NULL,
    event_seq       INTEGER NOT NULL DEFAULT 0,
    config_snapshot TEXT NULL,
    turn_started_at TEXT NULL,
    started_at      TEXT NOT NULL,
    finished_at     TEXT NULL,
    updated_at      TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS match_players (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    match_id           INTEGER NOT NULL REFERENCES matches (id) ON DELETE CASCADE,
    user_id            INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    username           TEXT NOT NULL,
    seat               INTEGER NOT NULL,
    coins              INTEGER NOT NULL DEFAULT 0,
    dice_value         INTEGER NULL,
    dice_display       TEXT NULL,
    spins_total        INTEGER NOT NULL DEFAULT 0,
    spins_remaining    INTEGER NOT NULL DEFAULT 0,
    second_roll_used   INTEGER NOT NULL DEFAULT 0,
    combo_streak       INTEGER NOT NULL DEFAULT 0,
    win_streak         INTEGER NOT NULL DEFAULT 0,
    spins_since_triple INTEGER NOT NULL DEFAULT 0,
    turns_taken        INTEGER NOT NULL DEFAULT 0,
    connection_status  TEXT NOT NULL DEFAULT 'online',
    last_seen_at       TEXT NULL,
    stats              TEXT NULL,
    placement          INTEGER NULL,
    created_at         TEXT NOT NULL,
    updated_at         TEXT NOT NULL,
    UNIQUE (match_id, user_id),
    UNIQUE (match_id, seat)
);

CREATE TABLE IF NOT EXISTS upgrades (
    upgrade_key TEXT PRIMARY KEY,
    name        TEXT NOT NULL,
    description TEXT NOT NULL,
    category    TEXT NOT NULL,
    rarity      TEXT NOT NULL,
    icon        TEXT NOT NULL,
    max_level   INTEGER NOT NULL,
    base_cost   INTEGER NOT NULL,
    cost_growth REAL NOT NULL,
    effects     TEXT NOT NULL,
    updated_at  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS player_upgrades (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    match_player_id INTEGER NOT NULL REFERENCES match_players (id) ON DELETE CASCADE,
    upgrade_key     TEXT NOT NULL,
    level           INTEGER NOT NULL DEFAULT 1,
    coins_spent     INTEGER NOT NULL DEFAULT 0,
    updated_at      TEXT NOT NULL,
    UNIQUE (match_player_id, upgrade_key)
);

CREATE TABLE IF NOT EXISTS shop_offers (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    match_player_id INTEGER NOT NULL REFERENCES match_players (id) ON DELETE CASCADE,
    slot            INTEGER NOT NULL,
    upgrade_key     TEXT NOT NULL,
    level           INTEGER NOT NULL,
    cost            INTEGER NOT NULL,
    purchased       INTEGER NOT NULL DEFAULT 0,
    created_at      TEXT NOT NULL,
    UNIQUE (match_player_id, slot)
);

CREATE TABLE IF NOT EXISTS dice_rolls (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    match_id        INTEGER NOT NULL REFERENCES matches (id) ON DELETE CASCADE,
    match_player_id INTEGER NOT NULL REFERENCES match_players (id) ON DELETE CASCADE,
    turn_number     INTEGER NOT NULL,
    raw_value       INTEGER NOT NULL,
    final_value     INTEGER NOT NULL,
    spins_granted   INTEGER NOT NULL,
    is_reroll       INTEGER NOT NULL DEFAULT 0,
    modifiers       TEXT NULL,
    created_at      TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS spins (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    match_id        INTEGER NOT NULL REFERENCES matches (id) ON DELETE CASCADE,
    match_player_id INTEGER NOT NULL REFERENCES match_players (id) ON DELETE CASCADE,
    turn_number     INTEGER NOT NULL,
    spin_index      INTEGER NOT NULL,
    reel_1          TEXT NOT NULL,
    reel_2          TEXT NOT NULL,
    reel_3          TEXT NOT NULL,
    outcome         TEXT NOT NULL,
    win_symbol      TEXT NULL,
    base_payout     INTEGER NOT NULL DEFAULT 0,
    final_payout    INTEGER NOT NULL DEFAULT 0,
    bonus_payout    INTEGER NOT NULL DEFAULT 0,
    coins_after     INTEGER NOT NULL DEFAULT 0,
    is_bonus_spin   INTEGER NOT NULL DEFAULT 0,
    modifiers       TEXT NULL,
    created_at      TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS game_events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    match_id   INTEGER NOT NULL REFERENCES matches (id) ON DELETE CASCADE,
    seq        INTEGER NOT NULL,
    type       TEXT NOT NULL,
    payload    TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE (match_id, seq)
);

CREATE TABLE IF NOT EXISTS action_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    match_id   INTEGER NOT NULL REFERENCES matches (id) ON DELETE CASCADE,
    user_id    INTEGER NOT NULL,
    action_id  TEXT NOT NULL,
    action     TEXT NOT NULL,
    result     TEXT NULL,
    created_at TEXT NOT NULL,
    UNIQUE (match_id, user_id, action_id)
);

CREATE TABLE IF NOT EXISTS ws_tickets (
    ticket     TEXT PRIMARY KEY,
    user_id    INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    expires_at INTEGER NOT NULL,
    used_at    INTEGER NULL
);

CREATE TABLE IF NOT EXISTS rate_limits (
    bucket            TEXT PRIMARY KEY,
    attempts          INTEGER NOT NULL DEFAULT 0,
    window_started_at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_matches_status ON matches (status);
CREATE INDEX IF NOT EXISTS idx_matches_code ON matches (room_code);
CREATE INDEX IF NOT EXISTS idx_spins_match ON spins (match_id);
CREATE INDEX IF NOT EXISTS idx_game_events_id ON game_events (id);
