<?php
/**
 * ROYAL SPIN — central game balance configuration.
 *
 * Everything that affects pacing, economy or probability lives here.
 * Change a number, restart the WebSocket service, done — no code edits needed.
 *
 * See `php bin/simulate.php --help` to re-validate the economy after changes.
 */

return [
    /* ------------------------------------------------------------------
     | Match pacing
     * ------------------------------------------------------------------ */
    'target_coins'   => 1500,   // GAME_TARGET  — first player to reach this wins
    'starting_coins' => 150,    // STARTING_COINS
    'min_players'    => 2,
    'max_players'    => 4,      // MAX_PLAYERS
    'shop_options'   => 4,      // SHOP_OPTIONS — cards offered after each turn

    /* ------------------------------------------------------------------
     | Symbols
     |
     | `weight` is a relative weight, not a percentage. With the default
     | values below they sum to 100 so they read as percentages, but any
     | positive numbers work — they are normalised before use.
     |
     | Ordered weakest -> strongest. `tier` is used by upgrades that talk
     | about "high value symbols" (Hot Reel, Royal Blessing, ...).
     * ------------------------------------------------------------------ */
    'symbols' => [
        'cherry'    => ['name' => 'Cherry',       'weight' => 20, 'pair' => 3,  'triple' => 12,  'tier' => 1],
        'lemon'     => ['name' => 'Lemon',        'weight' => 17, 'pair' => 4,  'triple' => 16,  'tier' => 1],
        'bell'      => ['name' => 'Bell',         'weight' => 14, 'pair' => 5,  'triple' => 22,  'tier' => 2],
        'horseshoe' => ['name' => 'Horseshoe',    'weight' => 12, 'pair' => 7,  'triple' => 30,  'tier' => 2],
        'star'      => ['name' => 'Star',         'weight' => 10, 'pair' => 9,  'triple' => 40,  'tier' => 3],
        'moneybag'  => ['name' => 'Money Bag',    'weight' => 8,  'pair' => 12, 'triple' => 52,  'tier' => 3],
        'crystal'   => ['name' => 'Crystal',      'weight' => 7,  'pair' => 15, 'triple' => 66,  'tier' => 3],
        'diamond'   => ['name' => 'Diamond',      'weight' => 5,  'pair' => 19, 'triple' => 85,  'tier' => 4],
        'crown'     => ['name' => 'Crown',        'weight' => 4,  'pair' => 25, 'triple' => 110, 'tier' => 5],
        'trophy'    => ['name' => 'Royal Trophy', 'weight' => 3,  'pair' => 34, 'triple' => 145, 'tier' => 5],
    ],
    /*
     | NOTE ON THE PAYOUT CURVE
     |
     | The brief's starting table topped out at 260 for a Trophy triple. Against
     | a 1500 coin target that is mathematically unavoidable trouble: to finish a
     | match in ~20 turns the target must be roughly 90x the average spin, which
     | made a single Trophy triple worth *more than the whole race* — about 0.5%
     | of simulated matches ended inside two minutes on one lucky spin.
     |
     | The high end is therefore compressed (Trophy 260 -> 145, Crown 170 -> 110)
     | while the ordering and the low end are untouched. A Trophy triple is still
     | by far the biggest moment in the game at ~1000 coins, but it now wins you
     | the lead instead of the match. Re-check with:
     |     php bin/simulate.php --matches=400
     */

    /**
     * Global multiplier applied to every pair/triple payout above.
     *
     * The pair/triple tables keep the design ratios from the brief; this single
     * knob sets the absolute speed of the economy. At 1.0 the raw tables yield
     * ~2.5 coins/spin, which would need ~500 turns to reach 1500 — far too slow.
     *
     * 9.5 gives ~24 coins/spin at match start, rising past 55 with a developed
     * build. Measured over 250 bot matches per player count:
     *     2 players -> 10:48   3 players -> 13:41   4 players -> 16:32
     * Reproduce with `php bin/simulate.php --matches=250 --players=3 --seed=11`.
     */
    'payout_scale' => 9.5,

    /**
     * Global multiplier on every upgrade price in config/upgrades.php.
     *
     * The catalogue stores each upgrade's *relative* price; this scales them
     * against the income above. Raise it to make builds slower and matches
     * longer, lower it to make upgrades feel generous.
     */
    'upgrade_cost_scale' => 0.55,

    'reels' => 3,

    /* ------------------------------------------------------------------
     | Dice
     * ------------------------------------------------------------------ */
    'dice' => [
        'faces'          => 6,     // standard W6
        'max_spins'      => 14,    // hard cap on spins per turn (anti-runaway)
        'lucky_start_turns' => 2,  // "Lucky Start" applies to the first N own turns
    ],

    /* ------------------------------------------------------------------
     | Anti-runaway caps for the upgrade engine
     * ------------------------------------------------------------------ */
    'caps' => [
        // A single symbol's weight may never be inflated beyond this factor
        // of its base weight, no matter how many upgrades stack.
        'symbol_weight_multiplier' => 4.0,
        // A symbol may never exceed this share of one reel after normalising.
        'symbol_probability'       => 0.45,
        // Ceilings for payout multipliers.
        'pair_multiplier'          => 2.50,
        'triple_multiplier'        => 2.50,
        'symbol_multiplier'        => 2.50,
        'combo_multiplier'         => 2.00,
        'total_payout_multiplier'  => 6.00,
        'conditional_reel_chance'  => 0.60, // Pair Hunter / Third Reel Magnet
        'wild_chance'              => 0.10,
    ],

    /* ------------------------------------------------------------------
     | Shop
     * ------------------------------------------------------------------ */
    'shop' => [
        // Relative chance of each rarity appearing in an offer slot.
        'rarity_weights' => [
            'common'    => 46,
            'rare'      => 32,
            'epic'      => 17,
            'legendary' => 5,
        ],
        // Offers are re-rolled at the start of each of the player's own turns.
        'reroll_each_turn' => true,
    ],

    /* ------------------------------------------------------------------
     | Rarity presentation (colour is used by the CSS as a custom property)
     * ------------------------------------------------------------------ */
    'rarities' => [
        'common'    => ['label' => 'Common',    'color' => '#8ea3b8'],
        'rare'      => ['label' => 'Rare',      'color' => '#4ea1ff'],
        'epic'      => ['label' => 'Epic',      'color' => '#b46bff'],
        'legendary' => ['label' => 'Legendary', 'color' => '#ffb32e'],
    ],

    /* ------------------------------------------------------------------
     | Connection / turn robustness
     * ------------------------------------------------------------------ */
    'connection' => [
        'heartbeat_seconds'      => 15,  // client ping interval
        'offline_after_seconds'  => 25,  // no heartbeat -> shown as "connection lost"
        'turn_timeout_seconds'   => 120, // an offline player's turn is auto-skipped after this
        'lobby_ttl_minutes'      => 180, // abandoned lobbies are reaped
        'match_ttl_minutes'      => 720, // abandoned live matches are reaped
    ],

    /* ------------------------------------------------------------------
     | Special upgrade tuning that is shared between engine + UI copy
     * ------------------------------------------------------------------ */
    'special' => [
        'royal_blessing' => [
            'spins_without_triple' => 12,  // pity threshold
            'bonus_per_spin'       => 0.03, // relative bonus to tier>=4 symbols per spin beyond threshold
            'max_bonus'            => 0.60,
        ],
        'lucky_streak'   => ['wins_required' => 3],
        'combo'          => ['step' => 0.10],
        'coin_rain'      => ['min' => 25, 'max' => 90],
        'jackpot_spark'  => ['bonus' => 500],
    ],
];
