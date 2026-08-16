<?php

declare(strict_types=1);

namespace RoyalSpin\Tests;

use RoyalSpin\Game\Modifiers;
use RoyalSpin\Game\PayoutEngine;
use RoyalSpin\Game\SlotEngine;
use RoyalSpin\Game\Symbols;
use RoyalSpin\Game\UpgradeEngine;
use RoyalSpin\Support\Config;
use RoyalSpin\Support\Rng;

final class SlotEngineTest extends TestCase
{
    /* ---------------------------------------------------------- weights */

    public function testBaseWeightsProduceTheConfiguredDistribution(): void
    {
        $modifiers = UpgradeEngine::compute([]);

        foreach ([1, 2, 3] as $reel) {
            $this->assertFloatEquals(
                1.0,
                array_sum($modifiers->reelProbabilities[$reel]),
                1e-9,
                "Reel {$reel} must be a valid distribution"
            );
        }

        // 20 of 100 total weight -> 20%.
        $this->assertFloatEquals(0.20, $modifiers->reelProbabilities[1]['cherry'], 1e-9);
        $this->assertFloatEquals(0.03, $modifiers->reelProbabilities[1]['trophy'], 1e-9);
    }

    public function testWeightedPickerFollowsTheWeights(): void
    {
        $rng     = new Rng(1234);
        $weights = ['a' => 70.0, 'b' => 30.0, 'c' => 0.0];
        $counts  = ['a' => 0, 'b' => 0, 'c' => 0];

        for ($i = 0; $i < 40000; $i++) {
            $counts[$rng->weighted($weights)]++;
        }

        $this->assertSame(0, $counts['c'], 'Zero weight must never be picked');
        $this->assertLessThan(0.02, abs($counts['a'] / 40000 - 0.7), 'Weighted picker is biased');
    }

    public function testSpinAlwaysReturnsThreeKnownSymbols(): void
    {
        $engine    = new SlotEngine(new Rng(99));
        $modifiers = UpgradeEngine::compute([]);

        for ($i = 0; $i < 500; $i++) {
            $outcome = $engine->spin($modifiers);
            $this->assertCount(3, $outcome->reels);
            foreach ($outcome->reels as $symbol) {
                $this->assertTrue(
                    Symbols::exists($symbol) || $symbol === Symbols::WILD,
                    "Unknown symbol produced: {$symbol}"
                );
            }
        }
    }

    /* --------------------------------------------------------- outcomes */

    public function testThreeIdenticalSymbolsAreATriple(): void
    {
        $result = SlotEngine::evaluate(['crown', 'crown', 'crown']);
        $this->assertSame(SlotEngine::OUTCOME_TRIPLE, $result['outcome']);
        $this->assertSame('crown', $result['symbol']);
    }

    public function testExactlyTwoIdenticalSymbolsAreAPair(): void
    {
        foreach ([['crown', 'crown', 'lemon'], ['crown', 'lemon', 'crown'], ['lemon', 'crown', 'crown']] as $reels) {
            $result = SlotEngine::evaluate($reels);
            $this->assertSame(SlotEngine::OUTCOME_PAIR, $result['outcome']);
            $this->assertSame('crown', $result['symbol']);
        }
    }

    public function testThreeDifferentSymbolsPayNothing(): void
    {
        $result = SlotEngine::evaluate(['cherry', 'lemon', 'bell']);
        $this->assertSame(SlotEngine::OUTCOME_NONE, $result['outcome']);
        $this->assertNull($result['symbol']);
    }

    public function testASingleSymbolNeverPays(): void
    {
        $payout = (new PayoutEngine(new Rng(1)))->calculate(
            new \RoyalSpin\Game\SpinOutcome(['cherry', 'lemon', 'bell'], SlotEngine::OUTCOME_NONE, null),
            UpgradeEngine::compute([])
        );
        $this->assertSame(0, $payout->total);
    }

    public function testWildCompletesATriple(): void
    {
        $result = SlotEngine::evaluate(['crown', Symbols::WILD, 'crown']);
        $this->assertSame(SlotEngine::OUTCOME_TRIPLE, $result['outcome']);
        $this->assertSame('crown', $result['symbol']);
    }

    public function testWildPairsWithTheMoreValuableSymbol(): void
    {
        $result = SlotEngine::evaluate([Symbols::WILD, 'cherry', 'crown']);
        $this->assertSame(SlotEngine::OUTCOME_PAIR, $result['outcome']);
        $this->assertSame('crown', $result['symbol'], 'Wild should pair with the better symbol');
    }

    /* ---------------------------------------------------------- payouts */

    public function testPairAndTriplePayoutsMatchTheConfiguredTable(): void
    {
        $scale     = Config::float('game.payout_scale');
        $modifiers = UpgradeEngine::compute([]);
        $engine    = new PayoutEngine(new Rng(5));

        $pair = $engine->calculate(
            new \RoyalSpin\Game\SpinOutcome(['star', 'star', 'lemon'], SlotEngine::OUTCOME_PAIR, 'star'),
            $modifiers
        );
        $this->assertSame((int) round(Symbols::pairPayout('star') * $scale), $pair->total);

        $triple = $engine->calculate(
            new \RoyalSpin\Game\SpinOutcome(['star', 'star', 'star'], SlotEngine::OUTCOME_TRIPLE, 'star'),
            $modifiers
        );
        $this->assertSame((int) round(Symbols::triplePayout('star') * $scale), $triple->total);
        $this->assertGreaterThan((float) $pair->total, (float) $triple->total, 'A triple must beat a pair');
    }

    public function testPayoutOrderingFollowsSymbolValue(): void
    {
        $engine    = new PayoutEngine(new Rng(5));
        $modifiers = UpgradeEngine::compute([]);
        $previous  = 0;

        foreach (Symbols::keys() as $symbol) {
            $payout = $engine->calculate(
                new \RoyalSpin\Game\SpinOutcome([$symbol, $symbol, $symbol], SlotEngine::OUTCOME_TRIPLE, $symbol),
                $modifiers
            );
            $this->assertGreaterThan((float) $previous, (float) $payout->total, "{$symbol} must pay more than the symbol below it");
            $previous = $payout->total;
        }
    }

    public function testComboMultiplierGrowsThenIsCapped(): void
    {
        $modifiers = UpgradeEngine::compute(['combo_bonus' => 1]);
        $engine    = new PayoutEngine(new Rng(5));
        $spin      = new \RoyalSpin\Game\SpinOutcome(['star', 'star', 'lemon'], SlotEngine::OUTCOME_PAIR, 'star');

        $first  = $engine->calculate($spin, $modifiers, 0, 0)->total;
        $second = $engine->calculate($spin, $modifiers, 1, 1)->total;
        $capped = $engine->calculate($spin, $modifiers, 50, 50)->total;

        $this->assertGreaterThan((float) $first, (float) $second, 'Combo should raise the payout');
        // Level 1 combo_bonus caps at +30%.
        $this->assertSame((int) round($first * 1.3), $capped, 'Combo must respect its cap');
    }

    public function testEmpiricalTripleRateMatchesTheAnalyticExpectation(): void
    {
        $engine    = new SlotEngine(new Rng(2026));
        $modifiers = UpgradeEngine::compute([]);

        $triples = 0;
        $pairs   = 0;
        $samples = 60000;

        for ($i = 0; $i < $samples; $i++) {
            $outcome = $engine->spin($modifiers);
            if ($outcome->isTriple()) {
                $triples++;
            } elseif ($outcome->outcome === SlotEngine::OUTCOME_PAIR) {
                $pairs++;
            }
        }

        // Analytic: sum p^3 = 1.9456%, sum 3p^2(1-p) = 32.92%
        $this->assertLessThan(0.004, abs($triples / $samples - 0.019456), 'Triple rate drifted');
        $this->assertLessThan(0.02, abs($pairs / $samples - 0.329232), 'Pair rate drifted');
    }

    public function testSeededRngIsReproducible(): void
    {
        $first  = (new SlotEngine(new Rng(4242)))->spin(UpgradeEngine::compute([]));
        $second = (new SlotEngine(new Rng(4242)))->spin(UpgradeEngine::compute([]));
        $this->assertSame($first->reels, $second->reels, 'A fixed seed must replay identically');
    }
}
