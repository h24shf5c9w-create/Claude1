<?php
/**
 * ROYAL SPIN — application bootstrap.
 *
 * Deliberately dependency-free: a tiny PSR-4 style autoloader for the
 * `RoyalSpin\` namespace plus environment loading. The whole project runs on a
 * stock PHP install (no Composer, no vendor directory).
 */

declare(strict_types=1);

if (defined('ROYAL_SPIN_BOOTSTRAPPED')) {
    return;
}
define('ROYAL_SPIN_BOOTSTRAPPED', true);
define('ROYAL_SPIN_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'RoyalSpin\\')) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen('RoyalSpin\\')));
    $file     = ROYAL_SPIN_ROOT . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require __DIR__ . '/Support/helpers.php';

\RoyalSpin\Support\Env::load(ROYAL_SPIN_ROOT . '/.env');

mb_internal_encoding('UTF-8');
date_default_timezone_set(\RoyalSpin\Support\Env::get('APP_TIMEZONE', 'UTC'));

if (\RoyalSpin\Support\Env::isDebug()) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}
