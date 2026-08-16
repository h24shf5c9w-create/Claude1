<?php
/** @var array<string,mixed> $user */
/** @var array<string,int> $stats */
/** @var list<array<string,mixed>> $history */
$labels = [
    'games_played'        => 'Matches played',
    'wins'                => 'Wins',
    'losses'              => 'Losses',
    'total_spins'         => 'Total spins',
    'total_pairs'         => 'Total pairs',
    'total_triples'       => 'Total triples',
    'crown_triples'       => 'Crown triples',
    'trophy_triples'      => 'Trophy triples',
    'highest_match_score' => 'Highest match score',
    'biggest_single_win'  => 'Biggest single win',
    'total_coins_won'     => 'Total coins won',
];
?>
<div class="stack" data-page="profile">
    <h1><?= e($user['username']) ?></h1>
    <p class="muted">Member since <?= e(substr((string) $user['created_at'], 0, 10)) ?></p>

    <section class="card">
        <div class="card__title"><h2>Lifetime statistics</h2></div>
        <div class="table-scroll">
            <table class="stat-table">
                <tbody>
                <?php foreach ($labels as $key => $label): ?>
                    <tr>
                        <th scope="row"><?= e($label) ?></th>
                        <td><?= coins($stats[$key] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <th scope="row">Win rate</th>
                    <td><?= ($stats['games_played'] ?? 0) > 0
                        ? number_format($stats['wins'] / $stats['games_played'] * 100, 1) . '%'
                        : '—' ?></td>
                </tr>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($history !== []): ?>
        <section class="card">
            <div class="card__title"><h2>Match history</h2></div>
            <ul class="history-list">
                <?php foreach ($history as $entry): ?>
                    <li>
                        <span class="place <?= $entry['won'] ? 'place--win' : '' ?>">
                            <?= $entry['placement'] === null ? '—' : '#' . (int) $entry['placement'] ?>
                        </span>
                        <span style="flex:1">
                            <strong><?= e($entry['room_code']) ?></strong>
                            <span class="muted"> · <?= (int) $entry['players'] ?> players</span>
                        </span>
                        <span class="coin-value"><?= coins($entry['coins']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <a class="button button--ghost button--block" href="/dashboard">Back to the dashboard</a>
</div>
