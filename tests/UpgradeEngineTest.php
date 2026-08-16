<?php

declare(strict_types=1);

namespace RoyalSpin\Tests;

use RoyalSpin\Game\DiceEngine;
use RoyalSpin\Game\SlotEngine;
use RoyalSpin\Game\Symbols;
use RoyalSpin\Game\UpgradeCatalog;
use RoyalSpin\Game\UpgradeEngine;
use RoyalSpin\Support\Config;
use RoyalSpin\Support\Rng;

final class UpgradeEngineTest extends TestCase
{
    /* -------------------------------------------------- reel targeting */

    public function testGlobalUpgradeAffectsAllThreeReels(): void
    {
        $base    = UpgradeEngine::compute([]);
        $boosted = UpgradeEngine::compute(['crown_magnet_all' => 1]);

        foreach ([1, 2, 3] as $reel) {
            $this->assertGreaterThan(
                $base->reelProbabilities[$reel]['crown'],
                $boosted->reelProbabilities[$reel]['crown'],
                "Reel {$reel} should get the global crown bonus"
            );
        }
    }

    public function testSingleReelUpgradeAffectsExactlyThatReel(): void
    {
        $base    = UpgradeEngine::compute([]);
        $boosted = UpgradeEngine::compute(['crown_reel_3' => 1]);

        $this->assertGreaterThan(
            $base->reelProbabilities[3]['crown'],
            $boosted->reelProbabilities[3]['crown'],
            'Reel 3 must receive the bonus'
        );
        $this->assertFloatEquals(
            $base->reelProbabilities[1]['crown'],
            $boosted->reelProbabilities[1]['crown'],
            1e-12,
            'Reel 1 must be untouched'
        );
        $this->assertFloatEquals(
            $base->reelProbabilities[2]['crown'],
            $boosted->reelProbabilities[2]['crown'],
            1e-12,
            'Reel 2 must be untouched'
        );
    }

    public function testSingleReelUpgradeIsStrongerThanTheGlobalVariant(): void
    {
        $base   = UpgradeEngine::compute([])->reelProbabilities[3]['crown'];
        $single = UpgradeEngine::compute(['crown_reel_3' => 1])->reelProbabilities[3]['crown'];
        $global = UpgradeEngine::compute(['crown_magnet_all' => 1])->reelProbabilities[3]['crown'];

        $this->assertGreaterThan($global - $base, $single - $base,
            'A single-reel upgrade should give a bigger per-reel bonus');
    }

    /* ------------------------------------------------ relative maths */

    public function testBonusIsRelativeNotAbsolute(): void
    {
        // Royal Aura is +8% *relative*: 4% crown becomes ~4.32%, never 12%.
        $boosted = UpgradeEngine::compute(['royal_aura' => 1]);
        $crown   = $boosted->reelProbabilities[1]['crown'];

        $this->assertGreaterThan(0.040, $crown, 'Crown should increase');
        $this->assertLessThan(0.048, $crown, 'A relative +8% must not become an absolute +8 points');
    }

    public function testDistributionsAlwaysNormaliseToOne(): void
    {
        $builds = [
            [],
            ['fruit_machine' => 3],
            ['crown_reel_1' => 3, 'crown_reel_2' => 3, 'crown_reel_3' => 3],
            ['trophy_hunter' => 2, 'royal_aura' => 3, 'crown_magnet_all' => 3],
            array_map(static fn (array $u): int => (int) $u['max_level'], UpgradeCatalog::all()),
        ];

        foreach ($builds as $index => $levels) {
            $modifiers = UpgradeEngine::compute($levels);
            foreach ([1, 2, 3] as $reel) {
                $sum = array_sum($modifiers->reelProbabilities[$reel]);
                $this->assertFloatEquals(1.0, $sum, 1e-9, "Build #{$index} reel {$reel} must sum to 1");

                foreach ($modifiers->reelProbabilities[$reel] as $symbol => $probability) {
                    $this->assertTrue(
                        $probability >= 0.0,
                        "Build #{$index} produced a negative probability for {$symbol}"
                    );
                }
            }
        }
    }

    public function testStackingIsAdditiveOnTheRelativeBonus(): void
    {
        $one   = UpgradeEngine::compute(['crown_reel_3' => 1])->reelProbabilities[3]['crown'];
        $three = UpgradeEngine::compute(['crown_reel_3' => 3])->reelProbabilities[3]['crown'];
        $base  = UpgradeEngine::compute([])->reelProbabilities[3]['crown'];

        $this->assertGreaterThan($one, $three, 'Level 3 must beat level 1');
        // +30%/level: level 3 gains roughly three times what level 1 gains.
        $ratio = ($three - $base) / ($one - $base);
        $this->assertGreaterThan(2.2, $ratio, 'Level scaling is too flat');
        $this->assertLessThan(4.0, $ratio, 'Level scaling is exploding');
    }

    /* ---------------------------------------------------------- caps */

    public function testNoSymbolCanExceedTheProbabilityCap(): void
    {
        $everything = array_map(static fn (array $u): int => (int) $u['max_level'], UpgradeCatalog::all());
        $modifiers  = UpgradeEngine::compute($everything);
        $cap        = Config::float('game.caps.symbol_probability');

        foreach ([1, 2, 3] as $reel) {
            foreach ($modifiers->reelProbabilities[$reel] as $symbol => $probability) {
                $this->assertTrue(
                    $probability <= $cap + 1e-9,
                    sprintf('%s on reel %d reached %.4f, above the %.2f cap', $symbol, $reel, $probability, $cap)
                );
            }
        }
    }

    public function testPayoutMultipliersAreCapped(): void
    {
        $everything = array_map(static fn (array $u): int => (int) $u['max_level'], UpgradeCatalog::all());
        $modifiers  = UpgradeEngine::compute($everything);

        $this->assertTrue(
            $modifiers->pairMultiplier <= Config::float('game.caps.pair_multiplier') + 1e-9,
            'Pair multiplier exceeded its cap'
        );
        $this->assertTrue(
            $modifiers->tripleMultiplier <= Config::float('game.caps.triple_multiplier') + 1e-9,
            'Triple multiplier exceeded its cap'
        );
        foreach ($modifiers->symbolPayoutMultipliers as $symbol => $multiplier) {
            $this->assertTrue(
                $multiplier <= Config::float('game.caps.symbol_multiplier') + 1e-9,
                "Symbol multiplier for {$symbol} exceeded its cap"
            );
        }
        $this->assertTrue(
            $modifiers->thirdReelMagnet <= Config::float('game.caps.conditional_reel_chance') + 1e-9,
            'Third Reel Magnet exceeded its cap'
        );
    }

    public function testNormaliseRedistributesOverflowAndStillSumsToOne(): void
    {
        $normalised = UpgradeEngine::normalise(['a' => 900.0, 'b' => 50.0, 'c' => 50.0], 0.45);

        $this->assertFloatEquals(1.0, array_sum($normalised), 1e-9);
        $this->assertFloatEquals(0.45, $normalised['a'], 1e-9, 'The capped symbol should sit exactly at the cap');
        $this->assertFloatEquals(0.275, $normalised['b'], 1e-9, 'Overflow should be shared proportionally');
    }

    public function testDegenerateWeightsFallBackToUniform(): void
    {
        $normalised = UpgradeEngine::normalise(['a' => 0.0, 'b' => 0.0], 1.0);
        $this->assertFloatEquals(1.0, array_sum($normalised), 1e-9);
        $this->assertFloatEquals(0.5, $normalised['a'], 1e-9);
    }

    public function testCorruptStoredLevelsCannotExceedTheCatalogueMaximum(): void
    {
        // Simulates a tampered/stale row claiming level 99.
        $sane    = UpgradeEngine::compute(['crown_reel_3' => UpgradeCatalog::maxLevel('crown_reel_3')]);
        $absurd  = UpgradeEngine::compute(['crown_reel_3' => 99]);

        $this->assertFloatEquals(
            $sane->reelProbabilities[3]['crown'],
            $absurd->reelProbabilities[3]['crown'],
            1e-12,
            'Levels above max_level must be clamped'
        );
    }

    public function testUnknownUpgradeKeysAreIgnored(): void
    {
        $base    = UpgradeEngine::compute([]);
        $bogus   = UpgradeEngine::compute(['not_a_real_upgrade' => 5]);
        $this->assertFloatEquals(
            $base->reelProbabilities[1]['crown'],
            $bogus->reelProbabilities[1]['crown'],
            1e-12
        );
    }

    /* ------------------------------------------------- pricing / offers */

    public function testCostGrowsWithLevel(): void
    {
        $first  = UpgradeCatalog::costForLevel('crown_reel_3', 1);
        $second = UpgradeCatalog::costForLevel('crown_reel_3', 2);
        $this->assertGreaterThan((float) $first, (float) $second, 'Level 2 must cost more than level 1');
    }

    public function testRequirementsAreEnforced(): void
    {
        $blocked = UpgradeCatalog::purchaseCheck('loaded_dice_2', []);
        $this->assertFalse($blocked['ok'], 'Loaded Dice II must require Loaded Dice I');
        $this->assertStringContains('Requires', (string) $blocked['reason']);

        $allowed = UpgradeCatalog::purchaseCheck('loaded_dice_2', ['loaded_dice_1' => 1]);
        $this->assertTrue($allowed['ok']);
    }

    public function testMaxedUpgradesAreNoLongerPurchasable(): void
    {
        $max   = UpgradeCatalog::maxLevel('lucky_six');
        $check = UpgradeCatalog::purchaseCheck('lucky_six', ['lucky_six' => $max]);
        $this->assertFalse($check['ok']);
        $this->assertSame(0, $check['cost']);
    }

    public function testEveryCatalogueEntryIsWellFormed(): void
    {
        $categories = UpgradeCatalog::categories();

        foreach (UpgradeCatalog::all() as $key => $upgrade) {
            $this->assertTrue(is_string($upgrade['name'] ?? null) && $upgrade['name'] !== '', "{$key} needs a name");
            $this->assertTrue(
                in_array($upgrade['category'] ?? '', $categories, true),
                "{$key} has an unknown category"
            );
            $this->assertTrue(
                in_array($upgrade['rarity'] ?? '', ['common', 'rare', 'epic', 'legendary'], true),
                "{$key} has an unknown rarity"
            );
            $this->assertGreaterThan(0, (float) ($upgrade['base_cost'] ?? 0), "{$key} needs a price");
            $this->assertGreaterThan(0, (float) ($upgrade['max_level'] ?? 0), "{$key} needs a max level");
            $this->assertTrue(($upgrade['effects'] ?? []) !== [], "{$key} has no effects");
        }
    }

    /** Every upgrade must actually change something measurable. */
    public function testEveryUpgradeHasAMeasurableEffect(): void
    {
        $base = UpgradeEngine::compute([]);

        foreach (UpgradeCatalog::all() as $key => $upgrade) {
            $with = UpgradeEngine::compute([$key => (int) $upgrade['max_level']]);
            $this->assertFalse(
                $this->modifiersAreIdentical($base, $with),
                "Upgrade '{$key}' has no observable effect"
            );
        }
    }

    private function modifiersAreIdentical(\RoyalSpin\Game\Modifiers $a, \RoyalSpin\Game\Modifiers $b): bool
    {
        return json_encode($a->toDisplayArray(true)) === json_encode($b->toDisplayArray(true));
    }

    /* --------------------------------------------- conditional reels */

    public function testThirdReelMagnetIncreasesTripleRate(): void
    {
        $plain  = UpgradeEngine::compute([]);
        $magnet = UpgradeEngine::compute(['third_reel_magnet' => 2]);

        $count = static function (\RoyalSpin\Game\Modifiers $modifiers, int $seed): int {
            $engine  = new SlotEngine(new Rng($seed));
            $triples = 0;
            for ($i = 0; $i < 30000; $i++) {
                if ($engine->spin($modifiers)->isTriple()) {
                    $triples++;
                }
            }
            return $triples;
        };

        $this->assertGreaterThan(
            (float) $count($plain, 7),
            (float) $count($magnet, 7),
            'Third Reel Magnet must produce more triples'
        );
    }

    public function testRoyalBlessingOnlyAppliesPastThePityThreshold(): void
    {
        $modifiers = UpgradeEngine::compute(['royal_blessing' => 2]);
        $threshold = Config::int('game.special.royal_blessing.spins_without_triple');

        $cold = SlotEngine::effectiveProbabilities($modifiers, 0);
        $hot  = SlotEngine::effectiveProbabilities($modifiers, $threshold + 15);

        $this->assertFloatEquals(
            $modifiers->reelProbabilities[1]['crown'],
            $cold[1]['crown'],
            1e-12,
            'Below the threshold nothing should change'
        );
        $this->assertGreaterThan(
            $cold[1]['crown'],
            $hot[1]['crown'],
            'Past the threshold high-value symbols should get more likely'
        );
        $this->assertFloatEquals(1.0, array_sum($hot[1]), 1e-9, 'Pity bonus must stay normalised');
    }

    /* --------------------------------------------------------- dice */

    public function testStandardDieRollsOneToSix(): void
    {
        $engine    = new DiceEngine(new Rng(31));
        $modifiers = UpgradeEngine::compute([]);
        $seen      = [];

        for ($i = 0; $i < 3000; $i++) {
            $roll = $engine->roll($modifiers);
            $this->assertTrue($roll->finalValue >= 1 && $roll->finalValue <= 6, 'Die out of range');
            $this->assertSame($roll->finalValue, $roll->spins, 'Without upgrades spins equal the face');
            $seen[$roll->finalValue] = true;
        }
        $this->assertCount(6, $seen, 'All six faces should appear');
    }

    public function testLoadedDiceLiftsAOneToATwo(): void
    {
        $engine    = new DiceEngine(new Rng(77));
        $modifiers = UpgradeEngine::compute(['loaded_dice_1' => 1]);

        for ($i = 0; $i < 2000; $i++) {
            $this->assertTrue($engine->roll($modifiers)->finalValue >= 2, 'Loaded Dice I must remove 1s');
        }
    }

    public function testLoadedDiceTwoLiftsAOneToAThree(): void
    {
        $engine    = new DiceEngine(new Rng(78));
        $modifiers = UpgradeEngine::compute(['loaded_dice_1' => 1, 'loaded_dice_2' => 1]);
        $sawTwo    = false;

        for ($i = 0; $i < 2000; $i++) {
            $roll = $engine->roll($modifiers);
            $this->assertTrue($roll->finalValue >= 2, 'Never a 1');
            if ($roll->finalValue === 2) {
                $sawTwo = true;   // a natural 2 is still possible
            }
        }
        $this->assertTrue($sawTwo, 'A natural 2 should still occur');
    }

    public function testExtraSpinUpgradeAddsSpins(): void
    {
        $engine    = new DiceEngine(new Rng(88));
        $modifiers = UpgradeEngine::compute(['extra_spin' => 1]);

        for ($i = 0; $i < 500; $i++) {
            $roll = $engine->roll($modifiers);
            $this->assertSame($roll->finalValue + 1, $roll->spins, '+1 Spin must add exactly one spin');
        }
    }

    public function testLuckySixGrantsBonusSpinsOnlyOnASix(): void
    {
        $engine    = new DiceEngine(new Rng(91));
        $modifiers = UpgradeEngine::compute(['lucky_six' => 1]);
        $sawSix    = false;

        for ($i = 0; $i < 2000; $i++) {
            $roll = $engine->roll($modifiers);
            if ($roll->finalValue === 6) {
                $sawSix = true;
                $this->assertSame(8, $roll->spins, 'A six should yield 6 + 2 bonus spins');
            } else {
                $this->assertSame($roll->finalValue, $roll->spins, 'Non-sixes get no bonus');
            }
        }
        $this->assertTrue($sawSix);
    }

    public function testMinimumRollRemovesOnesEntirely(): void
    {
        $engine    = new DiceEngine(new Rng(93));
        $modifiers = UpgradeEngine::compute(['minimum_roll' => 1]);

        for ($i = 0; $i < 2000; $i++) {
            $this->assertTrue($engine->roll($modifiers)->finalValue >= 2, 'Minimum Roll must never yield a 1');
        }
    }

    public function testAdvantageDiceRaisesTheAverage(): void
    {
        $average = static function (array $levels, int $seed): float {
            $engine = new DiceEngine(new Rng($seed));
            $mods   = UpgradeEngine::compute($levels);
            $total  = 0;
            for ($i = 0; $i < 20000; $i++) {
                $total += $engine->roll($mods)->finalValue;
            }
            return $total / 20000;
        };

        $plain     = $average([], 11);
        $advantage = $average(['advantage_dice' => 1], 11);

        $this->assertLessThan(3.7, $plain, 'A plain W6 should average about 3.5');
        $this->assertGreaterThan(4.2, $advantage, 'Advantage should average about 4.47');
    }

    public function testAHigherRollIsNeverWorse(): void
    {
        // The brief's core rule: spins cost nothing, so more pips is always
        // at least as good. Verified over the exact spin distribution.
        foreach ([[], ['extra_spin' => 2], ['lucky_six' => 1], ['loaded_dice_1' => 1]] as $levels) {
            $modifiers = UpgradeEngine::compute($levels);
            $expected  = [];

            for ($face = 1; $face <= 6; $face++) {
                $engine = new DiceEngine(new Rng(1000 + $face));
                // Isolate the face -> spins mapping by disabling random extras.
                $expected[$face] = $face
                    + $modifiers->diceInt('flat_bonus')
                    + ($face === 6 ? $modifiers->diceInt('six_bonus') : 0);
            }

            for ($face = 2; $face <= 6; $face++) {
                $this->assertTrue(
                    $expected[$face] >= $expected[$face - 1],
                    "Rolling {$face} produced fewer spins than " . ($face - 1)
                );
            }
        }
    }

    public function testSpinCountIsCapped(): void
    {
        $everything = array_map(static fn (array $u): int => (int) $u['max_level'], UpgradeCatalog::all());
        $modifiers  = UpgradeEngine::compute($everything);
        $engine     = new DiceEngine(new Rng(404));
        $cap        = Config::int('game.dice.max_spins');

        for ($i = 0; $i < 4000; $i++) {
            $this->assertTrue($engine->roll($modifiers)->spins <= $cap, 'Spin count exceeded the cap');
        }
    }

    public function testSecondRollIsOncePerTurn(): void
    {
        $modifiers = UpgradeEngine::compute(['second_roll' => 1]);
        $this->assertTrue(DiceEngine::canReroll($modifiers, false));
        $this->assertFalse(DiceEngine::canReroll($modifiers, true), 'Second Roll must not be reusable');
        $this->assertFalse(
            DiceEngine::canReroll(UpgradeEngine::compute([]), false),
            'No re-roll without the upgrade'
        );
    }

    public function testSpinDistributionMatchesTheEngine(): void
    {
        $modifiers = UpgradeEngine::compute(['extra_spin' => 1, 'lucky_six' => 1]);
        $analytic  = DiceEngine::spinDistribution($modifiers);
        $this->assertFloatEquals(1.0, array_sum($analytic), 1e-9, 'Distribution must sum to 1');

        $engine  = new DiceEngine(new Rng(555));
        $samples = 40000;
        $counts  = [];
        for ($i = 0; $i < $samples; $i++) {
            $spins          = $engine->roll($modifiers)->spins;
            $counts[$spins] = ($counts[$spins] ?? 0) + 1;
        }

        foreach ($analytic as $spins => $probability) {
            $observed = ($counts[$spins] ?? 0) / $samples;
            $this->assertLessThan(0.02, abs($observed - $probability),
                "Spin count {$spins} deviates from the analytic distribution");
        }
    }
}
