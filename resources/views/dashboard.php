<?php
/** @var array<string,mixed> $user */
/** @var array<string,int> $stats */
/** @var array<string,mixed>|null $activeRoom */
/** @var int|null $activeMatch */
/** @var list<array<string,mixed>> $history */
?>
<div class="stack" data-page="dashboard">

    <?php if (!empty($error)): ?>
        <div class="alert alert--error"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if (!empty($notice)): ?>
        <div class="alert alert--notice"><?= e($notice) ?></div>
    <?php endif; ?>

    <h1>Hello, <?= e($user['username']) ?></h1>

    <?php if ($activeMatch !== null): ?>
        <div class="card" style="border-color: rgba(242,193,78,.5)">
            <div class="card__title"><h2>You have a match running</h2></div>
            <p class="dim">Your seat, coins and upgrades are exactly where you left them.</p>
            <a class="button button--primary button--block button--huge" href="/game/<?= (int) $activeMatch ?>">
                Rejoin match
            </a>
        </div>
    <?php elseif ($activeRoom !== null): ?>
        <div class="card" style="border-color: rgba(242,193,78,.5)">
            <div class="card__title"><h2>You are in room <?= e($activeRoom['code']) ?></h2></div>
            <a class="button button--primary button--block button--huge" href="/lobby/<?= e($activeRoom['code']) ?>">
                Back to the lobby
            </a>
        </div>
    <?php endif; ?>

    <div class="hero-actions">
        <!-- ------------------------------------------------ create a room -->
        <section class="hero-card hero-card--create">
            <h2>Create room</h2>
            <p>Open a table and share the code.</p>

            <form class="stack" data-create-room>
                <div class="field">
                    <label id="player-count-label">Players</label>
                    <div class="player-count" role="radiogroup" aria-labelledby="player-count-label">
                        <?php for ($n = $minPlayers; $n <= $maxPlayers; $n++): ?>
                            <input type="radio" id="mp<?= $n ?>" name="max_players" value="<?= $n ?>"
                                   <?= $n === $maxPlayers ? 'checked' : '' ?>>
                            <label for="mp<?= $n ?>"><?= $n ?></label>
                        <?php endfor; ?>
                    </div>
                    <p class="field__hint">Target: <?= coins($targetCoins) ?> coins.</p>
                </div>
                <button type="submit" class="button button--primary button--block button--huge"
                        data-create-submit <?= $activeRoom !== null || $activeMatch !== null ? 'disabled' : '' ?>>
                    Create room
                </button>
            </form>
        </section>

        <!-- -------------------------------------------------- join a room -->
        <section class="hero-card hero-card--join">
            <h2>Join room</h2>
            <p>Enter the 4-character code.</p>

            <form class="stack" data-join-room>
                <div class="field">
                    <label for="room-code" class="visually-hidden">Room code</label>
                    <input id="room-code" class="code-input" name="code" type="text"
                           inputmode="text" autocomplete="off" autocapitalize="characters"
                           spellcheck="false" maxlength="4" placeholder="CODE" data-code-input>
                </div>
                <button type="submit" class="button button--royal button--block button--huge">Join</button>
            </form>
        </section>
    </div>

    <!-- ------------------------------------------------------- statistics -->
    <section class="card">
        <div class="card__title">
            <h2>Your record</h2>
            <a href="/profile" class="pill">All stats</a>
        </div>
        <div class="stat-grid">
            <div class="stat">
                <div class="stat__value"><?= coins($stats['games_played']) ?></div>
                <div class="stat__label">Matches</div>
            </div>
            <div class="stat">
                <div class="stat__value"><?= coins($stats['wins']) ?></div>
                <div class="stat__label">Wins</div>
            </div>
            <div class="stat">
                <div class="stat__value">
                    <?= $stats['games_played'] > 0
                        ? number_format($stats['wins'] / $stats['games_played'] * 100, 0) . '%'
                        : '—' ?>
                </div>
                <div class="stat__label">Win rate</div>
            </div>
            <div class="stat">
                <div class="stat__value"><?= coins($stats['total_spins']) ?></div>
                <div class="stat__label">Spins</div>
            </div>
        </div>
    </section>

    <?php if ($history !== []): ?>
        <section class="card">
            <div class="card__title"><h2>Recent matches</h2></div>
            <ul class="history-list">
                <?php foreach ($history as $entry): ?>
                    <li>
                        <span class="place <?= $entry['won'] ? 'place--win' : '' ?>">
                            <?= $entry['placement'] === null ? '—' : '#' . (int) $entry['placement'] ?>
                        </span>
                        <span style="flex:1">
                            <strong><?= e($entry['room_code']) ?></strong>
                            <span class="muted"> · <?= (int) $entry['players'] ?>P</span>
                            <?php if ($entry['duration'] !== null): ?>
                                <span class="muted"> · <?= (int) floor($entry['duration'] / 60) ?> min</span>
                            <?php endif; ?>
                        </span>
                        <span class="coin-value"><?= coins($entry['coins']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
</div>
