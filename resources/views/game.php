<?php
/** @var array<string,mixed> $state */
/** @var array<string,mixed> $user */
/** @var list<array<string,mixed>> $symbols */
/** @var string $wsUrl */
/** @var int $heartbeat */
/** @var bool $debug */
$match = $state['match'];
?>
<div class="connection-banner" data-connection-banner hidden>
    Connection lost — reconnecting…
</div>

<div class="game" data-page="game"
     data-game="<?= json_attr([
         'matchId'   => (int) $match['id'],
         'userId'    => (int) $user['id'],
         'wsUrl'     => $wsUrl,
         'wsPort'    => $wsPort,
         'heartbeat' => $heartbeat,
         'debug'     => (bool) $debug,
         'symbols'   => array_map(static fn (array $s): string => (string) $s['key'], $symbols),
         'state'     => $state,
     ]) ?>">

    <!-- ------------------------------------------------------------ topbar -->
    <div class="game-bar game__bar">
        <div class="game-bar__item">
            <span class="game-bar__label">Room</span>
            <span class="game-bar__value game-bar__value--code"><?= e($match['room_code']) ?></span>
        </div>
        <div class="game-bar__item">
            <span class="game-bar__label">Round</span>
            <span class="game-bar__value" data-round><?= (int) $match['round_number'] ?></span>
        </div>
        <div class="game-bar__item">
            <span class="game-bar__label">Target</span>
            <span class="game-bar__value"><?= coins((int) $match['target_coins']) ?></span>
        </div>
        <button type="button" class="icon-button" data-open-shop aria-label="Open upgrade shop"
                aria-haspopup="dialog">⚙</button>
    </div>

    <!-- ------------------------------------------------------- turn banner -->
    <div class="turn-banner game__banner" data-turn-banner role="status" aria-live="polite">
        Waiting…
    </div>

    <!-- ------------------------------------------------------------- coins -->
    <div class="game__coins stack">
        <div class="coin-display">
            <div class="coin-display__value" data-coins><?= coins((int) $state['you']['coins']) ?></div>
            <div class="coin-display__label">Your coins</div>
        </div>
        <div class="target-bar" role="progressbar" aria-label="Progress to target"
             aria-valuemin="0" aria-valuemax="<?= (int) $match['target_coins'] ?>"
             aria-valuenow="<?= (int) $state['you']['coins'] ?>" data-target-bar>
            <div class="target-bar__fill" data-target-fill></div>
        </div>
    </div>

    <!-- -------------------------------------------------------------- dice -->
    <div class="dice-zone game__dice">
        <div class="die" data-die aria-live="polite" aria-label="Dice result">–</div>
        <div class="dice-info">
            <div class="dice-info__spins" data-spins-remaining>0</div>
            <div class="dice-info__label">spins left</div>
            <div class="dice-info__mods" data-dice-mods></div>
        </div>
    </div>

    <!-- ---------------------------------------------------- slot machine -->
    <div class="machine game__machine" data-machine>
        <div class="reels">
            <?php foreach ([1, 2, 3] as $index): ?>
                <div class="reel" data-reel="<?= $index ?>" data-boost="0">
                    <div class="reel__strip" data-strip>
                        <div class="reel__cell">
                            <svg class="sym-crown" viewBox="0 0 64 64" role="img"
                                 aria-label="Reel <?= $index ?>"><use href="#sym-crown"></use></svg>
                        </div>
                    </div>
                    <div class="reel__glass"></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ------------------------------------------------------------ payout -->
    <div class="payout game__payout" data-payout aria-live="polite">
        <div>
            <div class="payout__amount payout__amount--none" data-payout-amount>Roll to begin</div>
            <div class="payout__breakdown" data-payout-breakdown></div>
        </div>
    </div>

    <!-- ----------------------------------------------------------- actions -->
    <div class="actions game__actions">
        <button type="button" class="button button--primary button--huge" data-action="roll" hidden>
            🎲 Roll the dice
        </button>
        <button type="button" class="button button--primary button--huge" data-action="spin" hidden>
            SPIN
        </button>
        <button type="button" class="button button--ghost" data-action="reroll" hidden>
            Use Second Roll
        </button>
        <div class="actions actions--split" data-shop-actions hidden>
            <button type="button" class="button button--royal" data-open-shop>Upgrades</button>
            <button type="button" class="button button--primary" data-action="end-turn">End turn</button>
        </div>
    </div>

    <!-- ----------------------------------------------------------- players -->
    <section class="game__players">
        <h2 class="visually-hidden">Players</h2>
        <div class="player-list" data-player-list></div>
    </section>

    <!-- ------------------------------------------------- owned upgrades -->
    <section class="card game__side">
        <div class="card__title"><h2>Your build</h2></div>
        <div class="owned-list" data-owned-list>
            <span class="muted" style="font-size:.84rem">No upgrades yet.</span>
        </div>

        <details style="margin-top:14px">
            <summary class="pill" style="cursor:pointer">Paytable</summary>
            <div class="table-scroll" style="margin-top:10px">
                <table class="stat-table">
                    <thead>
                        <tr><th>Symbol</th><th>Pair</th><th>Triple</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse($symbols) as $symbol): ?>
                            <tr>
                                <td>
                                    <svg class="sym-<?= e($symbol['key']) ?>" viewBox="0 0 64 64"
                                         width="18" height="18" aria-hidden="true"><use href="#sym-<?= e($symbol['key']) ?>"></use></svg>
                                    <?= e($symbol['name']) ?>
                                </td>
                                <td><?= coins((int) $symbol['pair']) ?></td>
                                <td><?= coins((int) $symbol['triple']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>

        <?php if ($debug): ?>
            <div class="debug-panel" data-debug-panel>
                <h3>Balance overlay (local only)</h3>
                <button type="button" class="button button--ghost button--small" data-debug-refresh>
                    Refresh
                </button>
                <pre data-debug-output>Press refresh.</pre>
            </div>
        <?php endif; ?>
    </section>
</div>

<!-- --------------------------------------------------------- shop sheet -->
<div class="sheet" data-shop-sheet role="dialog" aria-modal="true" aria-labelledby="shop-title" hidden>
    <div class="sheet__panel">
        <div class="sheet__grip"></div>
        <div class="sheet__head">
            <div>
                <h2 id="shop-title" style="margin:0">Upgrade shop</h2>
                <span class="muted" style="font-size:.8rem" data-shop-subtitle>Choose one</span>
            </div>
            <div style="display:flex;align-items:center;gap:10px">
                <span class="coin-value" data-shop-coins>0</span>
                <button type="button" class="icon-button" data-close-shop aria-label="Close shop">✕</button>
            </div>
        </div>
        <div class="sheet__body">
            <div class="shop-grid" data-shop-grid></div>
        </div>
    </div>
</div>

<!-- ------------------------------------------------------ big win overlay -->
<div class="bigwin" data-bigwin aria-hidden="true">
    <div class="bigwin__inner">
        <div class="bigwin__symbol" data-bigwin-symbol></div>
        <div class="bigwin__title" data-bigwin-title></div>
        <div class="bigwin__amount" data-bigwin-amount></div>
    </div>
</div>
