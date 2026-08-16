<?php
/**
 * Shown only when the app cannot prepare itself on first boot.
 *
 * Deliberately self-contained: no layout, no session, no database, no external
 * assets — those are exactly the things that might be broken here.
 *
 * @var string $setupProblem
 */
$writablePath = \RoyalSpin\Support\Installer::storagePath();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Royal Spin — setup needed</title>
    <style>
        :root { color-scheme: dark; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center;
            padding: 24px; background: #080a10; color: #eef2f8;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif; line-height: 1.55;
        }
        .box {
            max-width: 620px; width: 100%; padding: 28px;
            background: #11151f; border: 1px solid rgba(255,255,255,.1); border-radius: 18px;
        }
        h1 { margin: 0 0 6px; font-size: 1.4rem; }
        .sub { color: #9aa6bb; margin: 0 0 20px; font-size: .92rem; }
        .problem {
            padding: 14px 16px; border-radius: 12px; margin-bottom: 22px;
            background: rgba(255,107,129,.1); border: 1px solid rgba(255,107,129,.35); color: #ffc2cb;
        }
        h2 { font-size: .78rem; text-transform: uppercase; letter-spacing: .1em; color: #f2c14e; margin: 22px 0 8px; }
        code {
            background: #05070c; padding: 2px 7px; border-radius: 6px;
            font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: .86rem; word-break: break-all;
        }
        ol { margin: 0; padding-left: 20px; }
        li { margin-bottom: 9px; }
        .foot { margin-top: 24px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,.1);
                color: #6b768c; font-size: .82rem; }
    </style>
</head>
<body>
    <div class="box">
        <h1>👑 Royal Spin needs one small fix</h1>
        <p class="sub">The game sets itself up automatically, but something is blocking it.</p>

        <div class="problem"><?= htmlspecialchars($setupProblem, ENT_QUOTES, 'UTF-8') ?></div>

        <h2>How to fix it</h2>
        <ol>
            <li>
                In your hosting file manager or FTP client, find the folder
                <code><?= htmlspecialchars($writablePath, ENT_QUOTES, 'UTF-8') ?></code>
                (create it if it is missing).
            </li>
            <li>Set its permissions to <code>755</code>. If that is not enough, use <code>777</code>.</li>
            <li>Reload this page — nothing else is needed.</li>
        </ol>

        <h2>If it mentions pdo_sqlite</h2>
        <ol>
            <li>Open your hosting control panel (Plesk, cPanel, …) and look for <em>PHP settings</em> or <em>PHP extensions</em>.</li>
            <li>Enable <code>pdo_sqlite</code> and save.</li>
            <li>Reload this page.</li>
        </ol>

        <div class="foot">
            PHP <?= htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') ?> ·
            available database drivers:
            <code><?= htmlspecialchars(implode(', ', PDO::getAvailableDrivers()) ?: 'none', ENT_QUOTES, 'UTF-8') ?></code>
        </div>
    </div>
</body>
</html>
