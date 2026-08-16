<?php
/** @var array<string,mixed> $lobby */
/** @var array<string,mixed> $user */
$isHost = (int) $lobby['host_user_id'] === (int) $user['id'];
?>
<div class="stack" data-page="lobby"
     data-lobby="<?= json_attr([
         'roomId'     => (int) $lobby['id'],
         'code'       => (string) $lobby['code'],
         'userId'     => (int) $user['id'],
         'isHost'     => $isHost,
         'minPlayers' => (int) $lobby['min_players'],
     ]) ?>">

    <div class="card center">
        <p class="dim" style="margin-bottom:4px">Room code</p>
        <div class="lobby-code">
            <span class="lobby-code__value" data-room-code><?= e($lobby['code']) ?></span>
        </div>
        <button type="button" class="button button--ghost button--small" data-copy-code
                data-code="<?= e($lobby['code']) ?>">
            Copy code
        </button>
        <p class="muted" style="margin-top:12px;font-size:.84rem">
            Target <?= coins((int) $lobby['target_coins']) ?> coins ·
            up to <?= (int) $lobby['max_players'] ?> players
        </p>
    </div>

    <section class="card">
        <div class="card__title">
            <h2>Players</h2>
            <span class="pill" data-player-count>
                <?= count($lobby['players']) ?>/<?= (int) $lobby['max_players'] ?>
            </span>
        </div>

        <ul class="seat-list" data-seat-list>
            <!-- rendered by lobby.js; this server-rendered copy is the no-JS/first-paint state -->
            <?php foreach ($lobby['players'] as $player): ?>
                <li class="seat">
                    <span class="seat__avatar"><?= e(mb_strtoupper(mb_substr($player['username'], 0, 1))) ?></span>
                    <span class="seat__name"><?= e($player['username']) ?></span>
                    <span class="seat__tags">
                        <?php if ($player['is_host']): ?><span class="pill">Host</span><?php endif; ?>
                        <span class="pill"><?= $player['is_ready'] ? 'Ready' : 'Waiting' ?></span>
                    </span>
                </li>
            <?php endforeach; ?>
            <?php for ($i = count($lobby['players']); $i < (int) $lobby['max_players']; $i++): ?>
                <li class="seat seat--empty">
                    <span class="seat__avatar">?</span>
                    <span class="seat__name muted">Waiting for a player…</span>
                </li>
            <?php endfor; ?>
        </ul>
    </section>

    <div class="actions">
        <?php if ($isHost): ?>
            <button type="button" class="button button--primary button--huge" data-start-match disabled>
                Start match
            </button>
            <p class="muted center" style="font-size:.82rem" data-start-hint>
                Everyone must be ready before you can start.
            </p>
        <?php else: ?>
            <button type="button" class="button button--primary button--huge" data-ready-toggle
                    aria-pressed="false">
                I'm ready
            </button>
        <?php endif; ?>
        <button type="button" class="button button--danger" data-leave-room>
            <?= $isHost ? 'Close room' : 'Leave room' ?>
        </button>
    </div>
</div>
