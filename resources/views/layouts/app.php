<!DOCTYPE html>
<html lang="en" class="no-js">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0a0d14">
    <meta name="csrf-token" content="<?= e($csrf ?? '') ?>">
    <title><?= e(($title ?? 'Royal Spin') . ' — Royal Spin') ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
    <link rel="icon" href="<?= e(asset('assets/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<?php require ROYAL_SPIN_ROOT . '/resources/views/partials/symbols.php'; ?>

<div class="app-shell">
    <?php if (!empty($user)): ?>
        <header class="topbar">
            <a class="topbar__brand" href="/dashboard">
                <span class="topbar__crown"><svg viewBox="0 0 64 64" aria-hidden="true"><use href="#sym-crown"></use></svg></span>
                <span class="topbar__title">ROYAL SPIN</span>
            </a>
            <div class="topbar__actions">
                <button type="button" class="icon-button" data-sound-toggle aria-pressed="true"
                        aria-label="Toggle sound">
                    <span data-sound-on>🔊</span><span data-sound-off hidden>🔇</span>
                </button>
                <a class="topbar__user" href="/profile"><?= e($user['username']) ?></a>
                <form method="post" action="/logout" class="topbar__logout">
                    <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
                    <button type="submit" class="button button--ghost button--small">Sign out</button>
                </form>
            </div>
        </header>
    <?php endif; ?>

    <main class="app-main">
        <?= $content ?>
    </main>
</div>

<div class="toast-stack" id="toasts" role="status" aria-live="polite"></div>

<script type="module" src="<?= e(asset('assets/js/app.js')) ?>"></script>
</body>
</html>
