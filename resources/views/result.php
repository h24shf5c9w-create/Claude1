<?php
/** @var array<string,mixed> $results */
/** @var array<string,mixed> $user */
$winner = null;
foreach ($results['players'] as $player) {
    if ($player['is_winner']) { $winner = $player; break; }
}
?>
<div class="stack" data-page="result">
    <div class="result-hero">
        <div class="result-hero__trophy">
            <svg viewBox="0 0 64 64" aria-hidden="true"><use href="#sym-trophy"></use></svg>
        </div>
        <div class="result-hero__winner">
            <?= $winner === null ? 'Match ended' : e(mb_strtoupper($winner['username'])) . ' WINS' ?>
        </div>
        <div class="result-hero__sub">
            Room <?= e($results['room_code']) ?>
            <?php if ($results['duration_seconds'] !== null): ?>
                · <?= (int) floor($results['duration_seconds'] / 60) ?>:<?= str_pad((string) ($results['duration_seconds'] % 60), 2, '0', STR_PAD_LEFT) ?> min
            <?php endif; ?>
        </div>
    </div>

    <section class="card">
        <div class="card__title"><h2>Final standings</h2></div>
        <div class="standings">
            <?php foreach ($results['players'] as $player): ?>
                <div class="standing <?= $player['placement'] === 1 ? 'standing--first' : '' ?>">
                    <span class="standing__place"><?= $player['placement'] === null ? '—' : (int) $player['placement'] ?></span>
                    <span class="standing__name">
                        <?= e($player['username']) ?>
                        <?php if ((int) $player['user_id'] === (int) $user['id']): ?>
                            <span class="pill">You</span>
                        <?php endif; ?>
                    </span>
                    <span class="standing__coins"><?= coins((int) $player['coins']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <?php foreach ($results['players'] as $player): ?>
        <section class="card">
            <div class="card__title">
                <h2><?= e($player['username']) ?></h2>
                <span class="pill"><?= count($player['upgrades']) ?> upgrades</span>
            </div>

            <div class="table-scroll">
                <table class="stat-table">
                    <tbody>
                    <?php foreach (\RoyalSpin\Game\PlayerStats::describe($player['stats']) as $row): ?>
                        <tr><th scope="row"><?= e($row['label']) ?></th><td><?= e($row['value']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($player['upgrades'] !== []): ?>
                <div class="owned-list" style="margin-top:12px">
                    <?php foreach ($player['upgrades'] as $upgrade): ?>
                        <span class="owned-chip owned-chip--<?= e($upgrade['rarity']) ?>">
                            <svg viewBox="0 0 64 64" aria-hidden="true"><use href="#ico-<?= e($upgrade['icon']) ?>"></use></svg>
                            <?= e($upgrade['name']) ?>
                            <?php if ((int) $upgrade['max_level'] > 1): ?>
                                <span class="muted">L<?= (int) $upgrade['level'] ?></span>
                            <?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <div class="actions actions--split">
        <a class="button button--ghost" href="<?= e(url('/dashboard')) ?>">Main menu</a>
        <a class="button button--primary" href="<?= e(url('/dashboard')) ?>#create">New room</a>
    </div>
</div>
