-- ============================================================================
--  ROYAL SPIN — MySQL / MariaDB schema
--  Applied by:  php bin/migrate.php
-- ============================================================================

CREATE TABLE IF NOT EXISTS users (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(20)     NOT NULL,
    username_key  VARCHAR(20)     NOT NULL,          -- lowercased, enforces case-insensitive uniqueness
    email         VARCHAR(190)    NOT NULL,
    password_hash VARCHAR(255)    NOT NULL,
    created_at    DATETIME        NOT NULL,
    updated_at    DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username_key),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permanent, cross-match account statistics.
CREATE TABLE IF NOT EXISTS user_stats (
    user_id            BIGINT UNSIGNED NOT NULL,
    games_played       INT UNSIGNED NOT NULL DEFAULT 0,
    wins               INT UNSIGNED NOT NULL DEFAULT 0,
    losses             INT UNSIGNED NOT NULL DEFAULT 0,
    total_spins        INT UNSIGNED NOT NULL DEFAULT 0,
    total_pairs        INT UNSIGNED NOT NULL DEFAULT 0,
    total_triples      INT UNSIGNED NOT NULL DEFAULT 0,
    crown_triples      INT UNSIGNED NOT NULL DEFAULT 0,
    trophy_triples     INT UNSIGNED NOT NULL DEFAULT 0,
    highest_match_score INT UNSIGNED NOT NULL DEFAULT 0,
    biggest_single_win INT UNSIGNED NOT NULL DEFAULT 0,
    total_coins_won    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at         DATETIME NOT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_user_stats_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  ACTIVE rooms only. A room row is deleted when its match finishes, which is
--  what frees the 4-character code for re-use. Historical data lives in
--  `matches` / `match_players`, which are never deleted.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rooms (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code         VARCHAR(8)      NOT NULL,
    host_user_id BIGINT UNSIGNED NOT NULL,
    max_players  TINYINT UNSIGNED NOT NULL DEFAULT 4,
    target_coins INT UNSIGNED    NOT NULL DEFAULT 1500,
    status       VARCHAR(16)     NOT NULL DEFAULT 'lobby',   -- lobby | active
    match_id     BIGINT UNSIGNED NULL,
    created_at   DATETIME        NOT NULL,
    updated_at   DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rooms_code (code),
    KEY idx_rooms_match (match_id),
    CONSTRAINT fk_rooms_host FOREIGN KEY (host_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS room_players (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id    BIGINT UNSIGNED NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    seat       TINYINT UNSIGNED NOT NULL,
    is_host    TINYINT(1)      NOT NULL DEFAULT 0,
    is_ready   TINYINT(1)      NOT NULL DEFAULT 0,
    joined_at  DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_room_players_user (room_id, user_id),
    UNIQUE KEY uq_room_players_seat (room_id, seat),
    CONSTRAINT fk_room_players_room FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE,
    CONSTRAINT fk_room_players_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  Matches — the authoritative game state. Source of truth for reconnects.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS matches (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_code        VARCHAR(8)      NOT NULL,      -- snapshot; the room row may be gone
    status           VARCHAR(24)     NOT NULL DEFAULT 'active',  -- active | finished | abandoned
    phase            VARCHAR(32)     NOT NULL DEFAULT 'waiting_for_dice',
    target_coins     INT UNSIGNED    NOT NULL,
    starting_coins   INT UNSIGNED    NOT NULL,
    player_count     TINYINT UNSIGNED NOT NULL,
    current_seat     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    turn_number      INT UNSIGNED    NOT NULL DEFAULT 1,
    round_number     INT UNSIGNED    NOT NULL DEFAULT 1,
    winner_user_id   BIGINT UNSIGNED NULL,
    event_seq        INT UNSIGNED    NOT NULL DEFAULT 0,   -- monotonic per-match event counter
    config_snapshot  MEDIUMTEXT      NULL,                 -- JSON copy of the balance config used
    turn_started_at  DATETIME        NULL,
    started_at       DATETIME        NOT NULL,
    finished_at      DATETIME        NULL,
    updated_at       DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_matches_status (status),
    KEY idx_matches_code (room_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS match_players (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    match_id           BIGINT UNSIGNED NOT NULL,
    user_id            BIGINT UNSIGNED NOT NULL,
    username           VARCHAR(20)     NOT NULL,   -- snapshot for history rendering
    seat               TINYINT UNSIGNED NOT NULL,
    coins              INT NOT NULL DEFAULT 0,
    -- live turn state (this is what makes a mid-turn reconnect exact)
    dice_value         TINYINT UNSIGNED NULL,
    dice_display       VARCHAR(64)     NULL,       -- e.g. "5 (+1 Spin, Lucky Six)"
    spins_total        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    spins_remaining    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    second_roll_used   TINYINT(1)      NOT NULL DEFAULT 0,
    combo_streak       INT UNSIGNED    NOT NULL DEFAULT 0,
    win_streak         INT UNSIGNED    NOT NULL DEFAULT 0,
    spins_since_triple INT UNSIGNED    NOT NULL DEFAULT 0,
    turns_taken        INT UNSIGNED    NOT NULL DEFAULT 0,
    -- connection tracking
    connection_status  VARCHAR(16)     NOT NULL DEFAULT 'online', -- online | offline
    last_seen_at       DATETIME        NULL,
    -- per-match statistics (JSON blob, see Game\PlayerStats)
    stats              MEDIUMTEXT      NULL,
    placement          TINYINT UNSIGNED NULL,
    created_at         DATETIME        NOT NULL,
    updated_at         DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_match_players_user (match_id, user_id),
    UNIQUE KEY uq_match_players_seat (match_id, seat),
    KEY idx_match_players_user (user_id),
    CONSTRAINT fk_match_players_match FOREIGN KEY (match_id) REFERENCES matches (id) ON DELETE CASCADE,
    CONSTRAINT fk_match_players_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catalogue mirror of config/upgrades.php so purchases stay readable forever.
CREATE TABLE IF NOT EXISTS upgrades (
    upgrade_key VARCHAR(64)  NOT NULL,
    name        VARCHAR(80)  NOT NULL,
    description VARCHAR(400) NOT NULL,
    category    VARCHAR(24)  NOT NULL,
    rarity      VARCHAR(16)  NOT NULL,
    icon        VARCHAR(32)  NOT NULL,
    max_level   TINYINT UNSIGNED NOT NULL,
    base_cost   INT UNSIGNED NOT NULL,
    cost_growth DECIMAL(5,2) NOT NULL,
    effects     TEXT         NOT NULL,
    updated_at  DATETIME     NOT NULL,
    PRIMARY KEY (upgrade_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS player_upgrades (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    match_player_id BIGINT UNSIGNED NOT NULL,
    upgrade_key     VARCHAR(64)     NOT NULL,
    level           TINYINT UNSIGNED NOT NULL DEFAULT 1,
    coins_spent     INT UNSIGNED    NOT NULL DEFAULT 0,
    updated_at      DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_player_upgrade (match_player_id, upgrade_key),
    CONSTRAINT fk_player_upgrades_player FOREIGN KEY (match_player_id) REFERENCES match_players (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The 4 cards currently offered to a player. Persisted so a refresh cannot
-- re-roll the shop and so the server can verify the price of a purchase.
CREATE TABLE IF NOT EXISTS shop_offers (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    match_player_id BIGINT UNSIGNED NOT NULL,
    slot            TINYINT UNSIGNED NOT NULL,
    upgrade_key     VARCHAR(64)     NOT NULL,
    level           TINYINT UNSIGNED NOT NULL,
    cost            INT UNSIGNED    NOT NULL,
    purchased       TINYINT(1)      NOT NULL DEFAULT 0,
    created_at      DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shop_offer_slot (match_player_id, slot),
    CONSTRAINT fk_shop_offers_player FOREIGN KEY (match_player_id) REFERENCES match_players (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dice_rolls (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    match_id        BIGINT UNSIGNED NOT NULL,
    match_player_id BIGINT UNSIGNED NOT NULL,
    turn_number     INT UNSIGNED    NOT NULL,
    raw_value       TINYINT UNSIGNED NOT NULL,
    final_value     TINYINT UNSIGNED NOT NULL,
    spins_granted   TINYINT UNSIGNED NOT NULL,
    is_reroll       TINYINT(1)      NOT NULL DEFAULT 0,
    modifiers       TEXT            NULL,
    created_at      DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_dice_match (match_id),
    CONSTRAINT fk_dice_match FOREIGN KEY (match_id) REFERENCES matches (id) ON DELETE CASCADE,
    CONSTRAINT fk_dice_player FOREIGN KEY (match_player_id) REFERENCES match_players (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS spins (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    match_id        BIGINT UNSIGNED NOT NULL,
    match_player_id BIGINT UNSIGNED NOT NULL,
    turn_number     INT UNSIGNED    NOT NULL,
    spin_index      TINYINT UNSIGNED NOT NULL,
    reel_1          VARCHAR(16)     NOT NULL,
    reel_2          VARCHAR(16)     NOT NULL,
    reel_3          VARCHAR(16)     NOT NULL,
    outcome         VARCHAR(16)     NOT NULL,       -- none | pair | triple
    win_symbol      VARCHAR(16)     NULL,
    base_payout     INT UNSIGNED    NOT NULL DEFAULT 0,
    final_payout    INT UNSIGNED    NOT NULL DEFAULT 0,
    bonus_payout    INT UNSIGNED    NOT NULL DEFAULT 0,
    coins_after     INT             NOT NULL DEFAULT 0,
    is_bonus_spin   TINYINT(1)      NOT NULL DEFAULT 0,
    modifiers       TEXT            NULL,           -- JSON audit trail
    created_at      DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_spins_match (match_id),
    KEY idx_spins_player (match_player_id),
    CONSTRAINT fk_spins_match FOREIGN KEY (match_id) REFERENCES matches (id) ON DELETE CASCADE,
    CONSTRAINT fk_spins_player FOREIGN KEY (match_player_id) REFERENCES match_players (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  Realtime event outbox. Every authoritative mutation appends here inside the
--  same transaction; the WebSocket service tails the table and fans out. HTTP
--  clients can poll /api/match/{id}/events?since=N and get the identical stream.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS game_events (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    match_id   BIGINT UNSIGNED NOT NULL,
    seq        INT UNSIGNED    NOT NULL,
    type       VARCHAR(40)     NOT NULL,
    payload    MEDIUMTEXT      NOT NULL,
    created_at DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_game_events_seq (match_id, seq),
    KEY idx_game_events_id (id),
    CONSTRAINT fk_game_events_match FOREIGN KEY (match_id) REFERENCES matches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  Idempotency ledger. Client-generated action ids make SPIN/ROLL/BUY safe to
--  retry: a repeated action id returns the stored result instead of replaying.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS action_log (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    match_id   BIGINT UNSIGNED NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    action_id  VARCHAR(64)     NOT NULL,
    action     VARCHAR(32)     NOT NULL,
    result     MEDIUMTEXT      NULL,
    created_at DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_action_log (match_id, user_id, action_id),
    CONSTRAINT fk_action_log_match FOREIGN KEY (match_id) REFERENCES matches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Short-lived single-use tickets that authenticate a WebSocket upgrade.
CREATE TABLE IF NOT EXISTS ws_tickets (
    ticket     VARCHAR(64)     NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    expires_at INT UNSIGNED    NOT NULL,
    used_at    INT UNSIGNED    NULL,
    PRIMARY KEY (ticket),
    CONSTRAINT fk_ws_tickets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
    bucket            VARCHAR(64)  NOT NULL,
    attempts          INT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at INT UNSIGNED NOT NULL,
    PRIMARY KEY (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
