<?php
/**
 * ROYAL SPIN — upgrade catalogue.
 *
 * Upgrades are bought with coins earned during a match and are wiped when the
 * match ends. This file is the single source of truth; `php bin/migrate.php --seed`
 * mirrors it into the `upgrades` table so historical purchases stay readable.
 *
 * ---------------------------------------------------------------------------
 * EFFECT TYPES (all resolved server-side by src/Game/UpgradeEngine.php)
 * ---------------------------------------------------------------------------
 *  reel_symbol_rel   reels[], symbols[], value  Relative weight bonus. value=0.30
 *                                               means "+30% relative", i.e. a 4%
 *                                               crown becomes 5.2% *before*
 *                                               re-normalisation — never 34%.
 *  reel_tier_rel     reels[], min_tier, value   Same, applied to every symbol of
 *                                               at least `min_tier`.
 *  dice              key, value                 See DiceEngine: min_face,
 *                                               flat_bonus, six_bonus, second_roll,
 *                                               advantage, double_chance,
 *                                               reroll_ones, lucky_start,
 *                                               multiplier_chance
 *  payout_pair       value                      +value to the pair multiplier
 *  payout_triple     value                      +value to the triple multiplier
 *  payout_symbol     symbols[], value           +value for those symbols' wins
 *  combo             value                      Enables combo; value = max bonus
 *  pair_hunter       reel, value                Reel copies an already-visible symbol
 *  third_reel_magnet value                      Reel 3 completes a reel1==reel2 pair
 *  wild_chance       value                      Chance a reel counts as wild
 *  second_chance     value                      Chance of a free bonus spin after a loss
 *  coin_rain         value                      Chance of bonus coins on a triple
 *  royal_blessing    value                      Scales the pity bonus
 *  lucky_streak      value                      Coins granted on a 3-win streak
 *  jackpot_spark     value                      Chance of a jackpot on crown/trophy triple
 *
 * Every numeric `value` is PER LEVEL and stacks linearly, then gets clamped by
 * the caps in config/game.php.
 * ---------------------------------------------------------------------------
 */

/** Helper: build the three single-reel variants of a reel upgrade. */
$perReel = static function (
    string $keyPrefix,
    string $namePrefix,
    string $description,
    string $rarity,
    int $maxLevel,
    int $baseCost,
    float $costGrowth,
    array $effectTemplate,
    string $icon
): array {
    $out    = [];
    $roman  = [1 => 'I', 2 => 'II', 3 => 'III'];
    foreach ([1, 2, 3] as $reel) {
        $effects = [];
        foreach ($effectTemplate as $effect) {
            $effect['reels'] = [$reel];
            $effects[]       = $effect;
        }
        $out[$keyPrefix . '_reel_' . $reel] = [
            'name'        => $namePrefix . ' ' . $roman[$reel],
            'icon'        => $icon,
            'category'    => 'reel' . $reel,
            'rarity'      => $rarity,
            'max_level'   => $maxLevel,
            'base_cost'   => $baseCost,
            'cost_growth' => $costGrowth,
            'description' => $description,
            'effects'     => $effects,
        ];
    }
    return $out;
};

return array_merge(
    /* ==================================================================
     | 🎲 DICE
     * ================================================================== */
    [
        'lucky_start' => [
            'name' => 'Lucky Start', 'icon' => 'dice', 'category' => 'dice',
            'rarity' => 'common', 'max_level' => 1, 'base_cost' => 70, 'cost_growth' => 1.0,
            'description' => 'Your first two turns of the match each get +1 spin.',
            'effects' => [['type' => 'dice', 'key' => 'lucky_start', 'value' => 1]],
        ],
        'lucky_six' => [
            'name' => 'Lucky Six', 'icon' => 'dice', 'category' => 'dice',
            'rarity' => 'common', 'max_level' => 1, 'base_cost' => 130, 'cost_growth' => 1.0,
            'description' => 'Rolling a 6 grants 2 extra bonus spins.',
            'effects' => [['type' => 'dice', 'key' => 'six_bonus', 'value' => 2]],
        ],
        'loaded_dice_1' => [
            'name' => 'Loaded Dice I', 'icon' => 'dice', 'category' => 'dice',
            'rarity' => 'common', 'max_level' => 1, 'base_cost' => 95, 'cost_growth' => 1.0,
            'description' => 'A rolled 1 automatically becomes a 2.',
            'effects' => [['type' => 'dice', 'key' => 'min_face', 'value' => 2]],
        ],
        'loaded_dice_2' => [
            'name' => 'Loaded Dice II', 'icon' => 'dice', 'category' => 'dice',
            'rarity' => 'rare', 'max_level' => 1, 'base_cost' => 230, 'cost_growth' => 1.0,
            'requires' => ['loaded_dice_1' => 1],
            'description' => 'A rolled 1 becomes a 3 instead. Requires Loaded Dice I.',
            'effects' => [['type' => 'dice', 'key' => 'min_face', 'value' => 3]],
        ],
        'minimum_roll' => [
            'name' => 'Minimum Roll', 'icon' => 'dice', 'category' => 'dice',
            'rarity' => 'epic', 'max_level' => 1, 'base_cost' => 310, 'cost_growth' => 1.0,
            'description' => 'You can no longer roll a 1 — the die is re-rolled instead (even chance of 2–6).',
            'effects' => [['type' => 'dice', 'key' => 'reroll_ones', 'value' => 1]],
        ],
        'extra_spin' => [
            'name' => '+1 Spin', 'icon' => 'dice', 'category' => 'dice',
            'rarity' => 'rare', 'max_level' => 2, 'base_cost' => 265, 'cost_growth' => 1.9,
            'description' => 'Every dice roll grants +1 additional spin.',
            'effects' => [['type' => 'dice', 'key' => 'flat_bonus', 'value' => 1]],
        ],
        'second_roll' => [
            'name' => 'Second Roll', 'icon' => 'dice', 'category' => 'dice',
            'rarity' => 'rare', 'max_level' => 1, 'base_cost' => 210, 'cost_growth' => 1.0,
            'description' => 'Once per turn you may re-roll your die. The second result is final.',
            'effects' => [['type' => 'dice', 'key' => 'second_roll', 'value' => 1]],
        ],
        'dice_multiplier' => [
            'name' => 'Dice Multiplier', 'icon' => 'dice', 'category' => 'dice',
            'rarity' => 'rare', 'max_level' => 2, 'base_cost' => 300, 'cost_growth' => 1.8,
            'description' => '10% chance per level for +50% spins (rounded up).',
            'effects' => [['type' => 'dice', 'key' => 'multiplier_chance', 'value' => 0.10]],
        ],
        'golden_dice' => [
            'name' => 'Golden Dice', 'icon' => 'dice', 'category' => 'dice',
            'rarity' => 'epic', 'max_level' => 2, 'base_cost' => 390, 'cost_growth' => 1.8,
            'description' => '7% chance per level to double your rolled spin count.',
            'effects' => [['type' => 'dice', 'key' => 'double_chance', 'value' => 0.07]],
        ],
        'advantage_dice' => [
            'name' => 'Advantage Dice', 'icon' => 'dice', 'category' => 'dice',
            'rarity' => 'epic', 'max_level' => 1, 'base_cost' => 480, 'cost_growth' => 1.0,
            'description' => 'Roll two dice and always keep the higher result.',
            'effects' => [['type' => 'dice', 'key' => 'advantage', 'value' => 1]],
        ],
    ],

    /* ==================================================================
     | 🎰 ALL REELS  (weaker per point of cost than single-reel upgrades)
     * ================================================================== */
    [
        'royal_aura' => [
            'name' => 'Royal Aura', 'icon' => 'crown', 'category' => 'all_reels',
            'rarity' => 'rare', 'max_level' => 3, 'base_cost' => 250, 'cost_growth' => 1.7,
            'description' => '+8% relative Crown & Trophy chance on all three reels.',
            'effects' => [['type' => 'reel_symbol_rel', 'reels' => [1, 2, 3], 'symbols' => ['crown', 'trophy'], 'value' => 0.08]],
        ],
        'crown_magnet_all' => [
            'name' => 'Crown Magnet', 'icon' => 'crown', 'category' => 'all_reels',
            'rarity' => 'epic', 'max_level' => 3, 'base_cost' => 320, 'cost_growth' => 1.7,
            'description' => '+12% relative Crown chance on all three reels.',
            'effects' => [['type' => 'reel_symbol_rel', 'reels' => [1, 2, 3], 'symbols' => ['crown'], 'value' => 0.12]],
        ],
        'diamond_magnet_all' => [
            'name' => 'Diamond Magnet', 'icon' => 'diamond', 'category' => 'all_reels',
            'rarity' => 'rare', 'max_level' => 3, 'base_cost' => 235, 'cost_growth' => 1.65,
            'description' => '+12% relative Diamond chance on all three reels.',
            'effects' => [['type' => 'reel_symbol_rel', 'reels' => [1, 2, 3], 'symbols' => ['diamond'], 'value' => 0.12]],
        ],
        'balanced_reels' => [
            'name' => 'Royal Polish', 'icon' => 'sparkle', 'category' => 'all_reels',
            'rarity' => 'common', 'max_level' => 3, 'base_cost' => 140, 'cost_growth' => 1.6,
            'description' => '+7% relative chance for every high-value symbol (Star and above) on all reels.',
            'effects' => [['type' => 'reel_tier_rel', 'reels' => [1, 2, 3], 'min_tier' => 3, 'value' => 0.07]],
        ],
    ],

    /* ==================================================================
     | 👑 SYMBOLS  (symbol families across all reels)
     * ================================================================== */
    [
        'fruit_machine' => [
            'name' => 'Fruit Machine', 'icon' => 'cherry', 'category' => 'symbols',
            'rarity' => 'common', 'max_level' => 3, 'base_cost' => 65, 'cost_growth' => 1.55,
            'description' => '+25% relative Cherry & Lemon chance. Cheap, reliable early income.',
            'effects' => [['type' => 'reel_symbol_rel', 'reels' => [1, 2, 3], 'symbols' => ['cherry', 'lemon'], 'value' => 0.25]],
        ],
        'treasure_hunter' => [
            'name' => 'Treasure Hunter', 'icon' => 'moneybag', 'category' => 'symbols',
            'rarity' => 'rare', 'max_level' => 3, 'base_cost' => 270, 'cost_growth' => 1.7,
            'description' => '+12% relative Money Bag, Crystal & Diamond chance on all reels.',
            'effects' => [['type' => 'reel_symbol_rel', 'reels' => [1, 2, 3], 'symbols' => ['moneybag', 'crystal', 'diamond'], 'value' => 0.12]],
        ],
        'bell_ringer' => [
            'name' => 'Bell Ringer', 'icon' => 'bell', 'category' => 'symbols',
            'rarity' => 'common', 'max_level' => 3, 'base_cost' => 95, 'cost_growth' => 1.55,
            'description' => '+20% relative Bell & Horseshoe chance on all reels.',
            'effects' => [['type' => 'reel_symbol_rel', 'reels' => [1, 2, 3], 'symbols' => ['bell', 'horseshoe'], 'value' => 0.20]],
        ],
        'stargazer' => [
            'name' => 'Stargazer', 'icon' => 'star', 'category' => 'symbols',
            'rarity' => 'rare', 'max_level' => 3, 'base_cost' => 185, 'cost_growth' => 1.6,
            'description' => '+18% relative Star & Crystal chance on all reels.',
            'effects' => [['type' => 'reel_symbol_rel', 'reels' => [1, 2, 3], 'symbols' => ['star', 'crystal'], 'value' => 0.18]],
        ],
        'trophy_hunter' => [
            'name' => 'Trophy Hunter', 'icon' => 'trophy', 'category' => 'symbols',
            'rarity' => 'legendary', 'max_level' => 2, 'base_cost' => 720, 'cost_growth' => 1.8,
            'description' => '+15% relative Royal Trophy chance on all reels. The rarest prize, hunted.',
            'effects' => [['type' => 'reel_symbol_rel', 'reels' => [1, 2, 3], 'symbols' => ['trophy'], 'value' => 0.15]],
        ],
    ],

    /* ==================================================================
     | 1️⃣2️⃣3️⃣ SINGLE REEL — much stronger per reel than the global versions
     * ================================================================== */
    $perReel(
        'crown', 'Crown Reel',
        '+30% relative Crown chance on this reel only.',
        'epic', 3, 200, 1.75,
        [['type' => 'reel_symbol_rel', 'symbols' => ['crown'], 'value' => 0.30]],
        'crown'
    ),
    $perReel(
        'diamond', 'Diamond Reel',
        '+30% relative Diamond chance on this reel only.',
        'rare', 3, 160, 1.7,
        [['type' => 'reel_symbol_rel', 'symbols' => ['diamond'], 'value' => 0.30]],
        'diamond'
    ),
    $perReel(
        'royal', 'Royal Reel',
        '+25% relative Crown & Trophy chance on this reel only.',
        'epic', 2, 350, 1.8,
        [['type' => 'reel_symbol_rel', 'symbols' => ['crown', 'trophy'], 'value' => 0.25]],
        'trophy'
    ),
    $perReel(
        'hot', 'Hot Reel',
        '+15% relative chance for every symbol Star and above on this reel.',
        'rare', 3, 210, 1.7,
        [['type' => 'reel_tier_rel', 'min_tier' => 3, 'value' => 0.15]],
        'sparkle'
    ),
    $perReel(
        'fruit', 'Fruit Reel',
        '+40% relative Cherry & Lemon chance on this reel only.',
        'common', 2, 70, 1.6,
        [['type' => 'reel_symbol_rel', 'symbols' => ['cherry', 'lemon'], 'value' => 0.40]],
        'cherry'
    ),

    /* ==================================================================
     | ⚡ CONDITIONAL REEL MAGIC
     * ================================================================== */
    [
        'pair_hunter_reel_2' => [
            'name' => 'Pair Hunter II', 'icon' => 'magnet', 'category' => 'reel2',
            'rarity' => 'epic', 'max_level' => 2, 'base_cost' => 430, 'cost_growth' => 1.8,
            'description' => '18% chance per level that reel 2 copies the symbol already shown on reel 1.',
            'effects' => [['type' => 'pair_hunter', 'reel' => 2, 'value' => 0.18]],
        ],
        'pair_hunter_reel_3' => [
            'name' => 'Pair Hunter III', 'icon' => 'magnet', 'category' => 'reel3',
            'rarity' => 'epic', 'max_level' => 2, 'base_cost' => 430, 'cost_growth' => 1.8,
            'description' => '18% chance per level that reel 3 copies a symbol already shown on reel 1 or 2.',
            'effects' => [['type' => 'pair_hunter', 'reel' => 3, 'value' => 0.18]],
        ],
        'third_reel_magnet' => [
            'name' => 'Third Reel Magnet', 'icon' => 'magnet', 'category' => 'reel3',
            'rarity' => 'legendary', 'max_level' => 2, 'base_cost' => 680, 'cost_growth' => 1.85,
            'description' => 'When reels 1 and 2 match, reel 3 has a 20% chance per level to complete the triple.',
            'effects' => [['type' => 'third_reel_magnet', 'value' => 0.20]],
        ],
    ],

    /* ==================================================================
     | 💰 PAYOUT
     * ================================================================== */
    [
        'golden_pair' => [
            'name' => 'Golden Pair', 'icon' => 'coin', 'category' => 'payout',
            'rarity' => 'common', 'max_level' => 3, 'base_cost' => 135, 'cost_growth' => 1.6,
            'description' => 'Wins from two matching symbols pay +25%.',
            'effects' => [['type' => 'payout_pair', 'value' => 0.25]],
        ],
        'triple_profit' => [
            'name' => 'Triple Profit', 'icon' => 'coin', 'category' => 'payout',
            'rarity' => 'rare', 'max_level' => 3, 'base_cost' => 190, 'cost_growth' => 1.65,
            'description' => 'Wins from three matching symbols pay +20%.',
            'effects' => [['type' => 'payout_triple', 'value' => 0.20]],
        ],
        'crown_bonus' => [
            'name' => 'Crown Bonus', 'icon' => 'crown', 'category' => 'payout',
            'rarity' => 'rare', 'max_level' => 2, 'base_cost' => 250, 'cost_growth' => 1.7,
            'description' => 'All Crown wins pay +30%.',
            'effects' => [['type' => 'payout_symbol', 'symbols' => ['crown'], 'value' => 0.30]],
        ],
        'trophy_bonus' => [
            'name' => 'Trophy Bonus', 'icon' => 'trophy', 'category' => 'payout',
            'rarity' => 'epic', 'max_level' => 2, 'base_cost' => 330, 'cost_growth' => 1.75,
            'description' => 'All Royal Trophy wins pay +40%.',
            'effects' => [['type' => 'payout_symbol', 'symbols' => ['trophy'], 'value' => 0.40]],
        ],
        'combo_bonus' => [
            'name' => 'Combo Bonus', 'icon' => 'sparkle', 'category' => 'payout',
            'rarity' => 'rare', 'max_level' => 3, 'base_cost' => 220, 'cost_growth' => 1.65,
            'description' => 'Consecutive winning spins build a multiplier (+10% each, up to +30% per level). A losing spin resets it.',
            'effects' => [['type' => 'combo', 'value' => 0.30]],
        ],
    ],

    /* ==================================================================
     | ⚡ SPECIAL
     * ================================================================== */
    [
        'wild_chance' => [
            'name' => 'Wild Chance', 'icon' => 'sparkle', 'category' => 'special',
            'rarity' => 'legendary', 'max_level' => 2, 'base_cost' => 640, 'cost_growth' => 1.8,
            'description' => '3% chance per level that one reel turns Wild and completes any combination.',
            'effects' => [['type' => 'wild_chance', 'value' => 0.03]],
        ],
        'second_chance' => [
            'name' => 'Second Chance', 'icon' => 'magnet', 'category' => 'special',
            'rarity' => 'rare', 'max_level' => 2, 'base_cost' => 245, 'cost_growth' => 1.7,
            'description' => '12% chance per level of a free bonus spin after a completely losing spin.',
            'effects' => [['type' => 'second_chance', 'value' => 0.12]],
        ],
        'coin_rain' => [
            'name' => 'Coin Rain', 'icon' => 'coin', 'category' => 'special',
            'rarity' => 'rare', 'max_level' => 2, 'base_cost' => 235, 'cost_growth' => 1.7,
            'description' => '20% chance per level that a triple showers you with 25–90 bonus coins.',
            'effects' => [['type' => 'coin_rain', 'value' => 0.20]],
        ],
        'royal_blessing' => [
            'name' => 'Royal Blessing', 'icon' => 'crown', 'category' => 'special',
            'rarity' => 'epic', 'max_level' => 2, 'base_cost' => 400, 'cost_growth' => 1.75,
            'description' => 'After 12 spins without a triple, high-value symbols become steadily more likely. Resets on a triple.',
            'effects' => [['type' => 'royal_blessing', 'value' => 1.0]],
        ],
        'lucky_streak' => [
            'name' => 'Lucky Streak', 'icon' => 'star', 'category' => 'special',
            'rarity' => 'common', 'max_level' => 3, 'base_cost' => 165, 'cost_growth' => 1.6,
            'description' => 'Every third winning spin in a row pays 40 bonus coins per level.',
            'effects' => [['type' => 'lucky_streak', 'value' => 40]],
        ],
        'jackpot_spark' => [
            'name' => 'Jackpot Spark', 'icon' => 'trophy', 'category' => 'special',
            'rarity' => 'legendary', 'max_level' => 1, 'base_cost' => 820, 'cost_growth' => 1.0,
            'description' => 'A Crown or Trophy triple has a 15% chance to detonate a 500 coin jackpot.',
            'effects' => [['type' => 'jackpot_spark', 'value' => 0.15]],
        ],
    ]
);
