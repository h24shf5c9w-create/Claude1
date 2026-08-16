<?php

declare(strict_types=1);

/**
 * Balance simulator — runs the real engines with no database involved.
 *
 *   php bin/simulate.php                        # 100k spins + 200 bot matches
 *   php bin/simulate.php --spins=1000000
 *   php bin/simulate.php --matches=500 --players=3
 *   php bin/simulate.php --seed=42              # reproducible run
 *   php bin/simulate.php --build=crown          # simulate a specific build
 *
 * Timing model (used for the "match duration" estimate):
 *   dice roll + animation   ~2.4 s
 *   each spin               ~1.9 s   (1.3 s animation + reaction)
 *   shop decision per turn  ~4.5 s
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use RoyalSpin\Game\DiceEngine;
use RoyalSpin\Game\Modifiers;
use RoyalSpin\Game\PayoutEngine;
use RoyalSpin\Game\SlotEngine;
use RoyalSpin\Game\Symbols;
use RoyalSpin\Game\UpgradeCatalog;
use RoyalSpin\Game\UpgradeEngine;
use RoyalSpin\Support\Config;
use RoyalSpin\Support\Rng;

const SECONDS_PER_DICE = 2.4;
const SECONDS_PER_SPIN = 1.9;
const SECONDS_PER_SHOP = 4.5;

/** Bots stop investing once they hold this fraction of the target. */
const INVEST_UNTIL = 0.60;
/** Bots keep this much headroom over an upgrade's price before buying it. */
const BUY_BUFFER = 1.35;

$options = getopt('', ['spins::', 'matches::', 'players::', 'seed::', 'build::', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, <<<TXT
    Royal Spin balance simulator

      --spins=N      raw spins to sample for the payout table (default 100000)
      --matches=N    full bot matches to simulate (default 200)
      --players=N    players per simulated match (default 3)
      --seed=N       fixed RNG seed for a reproducible run
      --build=KEY    base build for the raw spin sample:
                     none | fruit | crown | payout | maxed
      --help         this text

    TXT);
    exit(0);
}

$spinSamples  = (int) ($options['spins'] ?? 100_000);
$matchSamples = (int) ($options['matches'] ?? 200);
$playerCount  = max(2, min(4, (int) ($options['players'] ?? 3)));
$seed         = isset($options['seed']) ? (int) $options['seed'] : null;
$buildName    = (string) ($options['build'] ?? 'none');

$rng = new Rng($seed);

fwrite(STDOUT, banner('ROYAL SPIN — BALANCE SIMULATION'));
fwrite(STDOUT, sprintf(
    "target=%d  start=%d  payout_scale=%.2f  players=%d  seed=%s\n\n",
    Config::int('game.target_coins'),
    Config::int('game.starting_coins'),
    Config::float('game.payout_scale'),
    $playerCount,
    $seed === null ? 'random' : (string) $seed
));

/* ==================================================================
 |  Part 1 — raw spin sample
 * ================================================================== */

$build     = buildFor($buildName);
$modifiers = UpgradeEngine::compute($build);

if ($spinSamples > 0) {
    $slot   = new SlotEngine($rng);
    $payout = new PayoutEngine($rng);

    $totalCoins       = 0;
    $pairs            = 0;
    $triples          = 0;
    $crownTriples     = 0;
    $trophyTriples    = 0;
    $biggest          = 0;
    $comboStreak      = 0;
    $winStreak        = 0;
    $spinsSinceTriple = 0;

    for ($i = 0; $i < $spinSamples; $i++) {
        $outcome = $slot->spin($modifiers, $spinsSinceTriple);
        $result  = $payout->calculate($outcome, $modifiers, $comboStreak, $winStreak);

        $totalCoins += $result->total;
        $biggest     = max($biggest, $result->total);

        if ($outcome->isWin()) {
            $comboStreak++;
            $winStreak++;
        } else {
            $comboStreak = 0;
            $winStreak   = 0;
        }

        if ($outcome->outcome === SlotEngine::OUTCOME_PAIR) {
            $pairs++;
        } elseif ($outcome->isTriple()) {
            $triples++;
            if ($outcome->winSymbol === 'crown') {
                $crownTriples++;
            } elseif ($outcome->winSymbol === 'trophy') {
                $trophyTriples++;
            }
        }
        $spinsSinceTriple = $outcome->isTriple() ? 0 : $spinsSinceTriple + 1;
    }

    fwrite(STDOUT, banner("SPIN SAMPLE — build '{$buildName}' — " . number_format($spinSamples) . ' spins'));
    row('Average payout per spin', number_format($totalCoins / $spinSamples, 3) . ' coins');
    row('Analytic expected value', number_format(PayoutEngine::expectedValue($modifiers), 3) . ' coins');
    row('Pair rate', percent($pairs / $spinSamples));
    row('Triple rate', percent($triples / $spinSamples));
    row('Crown triple rate', percent($crownTriples / $spinSamples));
    row('Trophy triple rate', percent($trophyTriples / $spinSamples));
    row('Any-win rate', percent(($pairs + $triples) / $spinSamples));
    row('Biggest single payout', number_format($biggest) . ' coins');
    fwrite(STDOUT, "\n");
}

/* ==================================================================
 |  Part 2 — full bot matches
 * ================================================================== */

if ($matchSamples > 0) {
    $target   = Config::int('game.target_coins');
    $start    = Config::int('game.starting_coins');
    $shopSize = Config::int('game.shop_options', 4);

    $durations   = [];
    $turnCounts  = [];
    $spinCounts  = [];
    $upgradeBuys = [];
    $winnerCoins = [];
    $diceTotals  = [];
    $diceRolls   = [];

    for ($match = 0; $match < $matchSamples; $match++) {
        $bots = [];
        for ($seat = 0; $seat < $playerCount; $seat++) {
            $bots[$seat] = [
                'coins'      => $start,
                'levels'     => [],
                'modifiers'  => UpgradeEngine::compute([]),
                'turns'      => 0,
                'spins'      => 0,
                'buys'       => 0,
                'sinceTriple'=> 0,
                'combo'      => 0,
                'wins'       => 0,
                'diceTotal'  => 0,
                'diceRolls'  => 0,
            ];
        }

        $slot     = new SlotEngine($rng);
        $payout   = new PayoutEngine($rng);
        $dice     = new DiceEngine($rng);
        $seconds  = 0.0;
        $turns    = 0;
        $winner   = null;
        $seat     = 0;

        // Hard stop so a mis-tuned config cannot hang the simulator.
        while ($winner === null && $turns < 4000) {
            $bot = &$bots[$seat];

            $roll = $dice->roll($bot['modifiers'], $bot['turns']);
            $bot['diceTotal'] += $roll->finalValue;
            $bot['diceRolls']++;
            $seconds += SECONDS_PER_DICE;

            $remaining = $roll->spins;
            while ($remaining > 0) {
                $remaining--;
                $bot['spins']++;
                $seconds += SECONDS_PER_SPIN;

                $outcome = $slot->spin($bot['modifiers'], $bot['sinceTriple']);
                $result  = $payout->calculate($outcome, $bot['modifiers'], $bot['combo'], $bot['wins']);

                $bot['coins'] += $result->total;

                if ($outcome->isWin()) {
                    $bot['combo']++;
                    $bot['wins']++;
                } else {
                    $bot['combo'] = 0;
                    $bot['wins']  = 0;
                    $secondChance = $bot['modifiers']->specialFloat('second_chance');
                    if ($secondChance > 0.0 && $rng->chance($secondChance)) {
                        $remaining++;
                    }
                }
                $bot['sinceTriple'] = $outcome->isTriple() ? 0 : $bot['sinceTriple'] + 1;

                if ($bot['coins'] >= $target) {
                    $winner = $seat;
                    break;
                }
            }

            if ($winner !== null) {
                break;
            }

            // --- shop step -------------------------------------------------
            // Models a competent player rather than a coin sink: invest while
            // the target is still far away, pick the offer with the best
            // expected-value gain per coin, and stop spending once the target
            // is within reach so the last stretch is a genuine race.
            $seconds += SECONDS_PER_SHOP;

            $investing = $bot['coins'] < $target * INVEST_UNTIL;
            if ($investing) {
                $candidates = UpgradeCatalog::purchasableKeys($bot['levels']);
                if ($candidates !== []) {
                    $offers      = $rng->pickDistinct($candidates, $shopSize);
                    $currentEv   = PayoutEngine::expectedValue($bot['modifiers']);
                    $bestKey     = null;
                    $bestValue   = 0.0;
                    $bestCost    = 0;
                    $bestLevel   = 0;

                    foreach ($offers as $key) {
                        $check = UpgradeCatalog::purchaseCheck($key, $bot['levels']);
                        if (!$check['ok'] || $check['cost'] <= 0) {
                            continue;
                        }
                        // Keep a buffer instead of going all-in every turn.
                        if ($bot['coins'] < $check['cost'] * BUY_BUFFER) {
                            continue;
                        }

                        $trial   = $bot['levels'];
                        $trial[$key] = $check['next_level'];
                        $gain    = PayoutEngine::expectedValue(UpgradeEngine::compute($trial)) - $currentEv;
                        // Dice upgrades add spins rather than value per spin,
                        // so credit them with the extra spins they generate.
                        $gain   += diceGain($bot['levels'], $trial, $bot['turns']) * $currentEv;

                        $value = $gain / $check['cost'];
                        if ($value > $bestValue) {
                            $bestValue = $value;
                            $bestKey   = $key;
                            $bestCost  = $check['cost'];
                            $bestLevel = $check['next_level'];
                        }
                    }

                    if ($bestKey !== null) {
                        $bot['coins']           -= $bestCost;
                        $bot['levels'][$bestKey] = $bestLevel;
                        $bot['modifiers']        = UpgradeEngine::compute($bot['levels']);
                        $bot['buys']++;
                    }
                }
            }

            $bot['turns']++;
            $turns++;
            unset($bot);
            $seat = ($seat + 1) % $playerCount;
        }

        if ($winner === null) {
            continue;
        }

        $durations[]   = $seconds;
        $turnCounts[]  = $bots[$winner]['turns'] + 1;
        $spinCounts[]  = $bots[$winner]['spins'];
        $upgradeBuys[] = $bots[$winner]['buys'];
        $winnerCoins[] = $bots[$winner]['coins'];
        $diceTotals[]  = $bots[$winner]['diceTotal'];
        $diceRolls[]   = $bots[$winner]['diceRolls'];
    }

    $completed = count($durations);
    fwrite(STDOUT, banner("BOT MATCHES — {$completed}/{$matchSamples} completed, {$playerCount} players"));

    if ($completed === 0) {
        fwrite(STDERR, "No match reached the target — the economy is far too slow.\n");
        exit(1);
    }

    $avgDuration = array_sum($durations) / $completed;
    row('Average match duration', formatDuration($avgDuration));
    row('Median match duration', formatDuration(median($durations)));
    row('Fastest / slowest', formatDuration(min($durations)) . '  /  ' . formatDuration(max($durations)));
    row('Within the 10–20 min goal', percent(count(array_filter(
        $durations,
        static fn (float $d): bool => $d >= 600 && $d <= 1200
    )) / $completed));
    row('Blowouts (under 5 min)', percent(count(array_filter(
        $durations,
        static fn (float $d): bool => $d < 300
    )) / $completed));
    row('Grinds (over 25 min)', percent(count(array_filter(
        $durations,
        static fn (float $d): bool => $d > 1500
    )) / $completed));
    row('Average turns (winner)', number_format(array_sum($turnCounts) / $completed, 1));
    row('Average total turns', number_format(array_sum($turnCounts) / $completed * $playerCount, 1));
    row('Average spins (winner)', number_format(array_sum($spinCounts) / $completed, 1));
    row('Average upgrades bought', number_format(array_sum($upgradeBuys) / $completed, 1));
    row('Average winning score', number_format(array_sum($winnerCoins) / $completed) . ' coins');
    row('Average dice roll', number_format(array_sum($diceTotals) / max(1, array_sum($diceRolls)), 2));
    fwrite(STDOUT, "\n");

    if ($avgDuration < 600 || $avgDuration > 1200) {
        fwrite(STDOUT, "Hint: adjust game.payout_scale or game.target_coins to land inside 10–20 minutes.\n\n");
    }
}

exit(0);

/* ------------------------------------------------------------------ utils */

/**
 * Relative change in expected spins per turn between two builds. Lets the bot
 * compare a dice upgrade against a reel upgrade on the same scale.
 *
 * @param array<string,int> $before
 * @param array<string,int> $after
 */
function diceGain(array $before, array $after, int $turnsTaken): float
{
    $expected = static function (array $levels) use ($turnsTaken): float {
        $total = 0.0;
        foreach (DiceEngine::spinDistribution(UpgradeEngine::compute($levels), $turnsTaken) as $spins => $probability) {
            $total += $spins * $probability;
        }
        return $total;
    };

    $baseline = $expected($before);
    if ($baseline <= 0.0) {
        return 0.0;
    }
    return ($expected($after) - $baseline) / $baseline;
}

/** @return array<string,int> */
function buildFor(string $name): array
{
    return match ($name) {
        'fruit'  => ['fruit_machine' => 3, 'golden_pair' => 3],
        'crown'  => ['crown_reel_1' => 3, 'crown_reel_2' => 3, 'crown_reel_3' => 3, 'crown_bonus' => 2],
        'payout' => ['golden_pair' => 3, 'triple_profit' => 3, 'combo_bonus' => 3],
        'maxed'  => array_map(
            static fn (array $upgrade): int => (int) $upgrade['max_level'],
            UpgradeCatalog::all()
        ),
        default  => [],
    };
}

function banner(string $title): string
{
    return "\n" . str_repeat('=', 62) . "\n  " . $title . "\n" . str_repeat('=', 62) . "\n";
}

function row(string $label, string $value): void
{
    fwrite(STDOUT, sprintf("  %-28s %s\n", $label, $value));
}

function percent(float $ratio): string
{
    return number_format($ratio * 100, 3) . ' %';
}

function formatDuration(float $seconds): string
{
    return sprintf('%d:%02d min', (int) ($seconds / 60), (int) round(fmod($seconds, 60)));
}

/** @param list<float> $values */
function median(array $values): float
{
    sort($values);
    $count = count($values);
    if ($count === 0) {
        return 0.0;
    }
    $middle = (int) floor($count / 2);
    return $count % 2 === 0 ? ($values[$middle - 1] + $values[$middle]) / 2 : $values[$middle];
}
