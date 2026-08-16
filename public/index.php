<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use RoyalSpin\Http\Controllers\AuthController;
use RoyalSpin\Http\Controllers\DashboardController;
use RoyalSpin\Http\Controllers\GameController;
use RoyalSpin\Http\Controllers\RoomController;
use RoyalSpin\Http\Request;
use RoyalSpin\Http\Response;
use RoyalSpin\Http\Router;
use RoyalSpin\Support\Env;
use RoyalSpin\Support\Session;

// The PHP built-in server serves existing files itself; this guard is only for
// the (unlikely) case of it routing a static path through the front controller.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (is_string($file) && is_file($file) && realpath($file) !== __FILE__) {
        return false;
    }
}

Session::start();

$request = Request::capture();
$router  = new Router();

$auth      = new AuthController();
$dashboard = new DashboardController();
$rooms     = new RoomController();
$game      = new GameController();

/* ------------------------------------------------------------------ public */
$router->get('/', static function (Request $request): never {
    Response::redirect(Session::userId() === null ? '/login' : '/dashboard');
});
$router->get('/login', [$auth, 'showLogin'](...));
$router->post('/login', [$auth, 'login'](...));
$router->get('/register', [$auth, 'showRegister'](...));
$router->post('/register', [$auth, 'register'](...));
$router->post('/logout', [$auth, 'logout'](...), auth: true);

/* --------------------------------------------------------------- app pages */
$router->get('/dashboard', [$dashboard, 'index'](...), auth: true);
$router->get('/profile', [$dashboard, 'profile'](...), auth: true);
$router->get('/lobby/{code}', [$rooms, 'lobby'](...), auth: true);
$router->get('/game/{matchId}', [$game, 'show'](...), auth: true);
$router->get('/result/{matchId}', [$game, 'result'](...), auth: true);

/* --------------------------------------------------------------- room API */
$router->post('/api/rooms', [$rooms, 'create'](...), auth: true);
$router->post('/api/rooms/join', [$rooms, 'join'](...), auth: true);
$router->get('/api/rooms/{roomId}', [$rooms, 'state'](...), auth: true);
$router->post('/api/rooms/{roomId}/ready', [$rooms, 'ready'](...), auth: true);
$router->post('/api/rooms/{roomId}/leave', [$rooms, 'leave'](...), auth: true);
$router->post('/api/rooms/{roomId}/start', [$rooms, 'start'](...), auth: true);

/* --------------------------------------------------------------- game API */
$router->get('/api/ws-ticket', [$game, 'websocketTicket'](...), auth: true);
$router->get('/api/match/{matchId}/state', [$game, 'state'](...), auth: true);
$router->get('/api/match/{matchId}/events', [$game, 'events'](...), auth: true);
$router->post('/api/match/{matchId}/roll', [$game, 'roll'](...), auth: true);
$router->post('/api/match/{matchId}/reroll', [$game, 'reroll'](...), auth: true);
$router->post('/api/match/{matchId}/spin', [$game, 'spin'](...), auth: true);
$router->post('/api/match/{matchId}/buy', [$game, 'buy'](...), auth: true);
$router->post('/api/match/{matchId}/end-turn', [$game, 'endTurn'](...), auth: true);
$router->post('/api/match/{matchId}/heartbeat', [$game, 'heartbeat'](...), auth: true);
$router->get('/api/match/{matchId}/debug', [$game, 'debugBalance'](...), auth: true);

try {
    $router->dispatch($request);
} catch (Throwable $exception) {
    error_log('[royal-spin] ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine());

    if ($request->wantsJson) {
        Response::json(
            Env::isDebug()
                ? ['ok' => false, 'error' => $exception->getMessage(), 'trace' => $exception->getTraceAsString()]
                : ['ok' => false, 'error' => 'Something went wrong. Please try again.'],
            500
        );
    }

    Response::view('errors/500', [
        'title'   => 'Something went wrong',
        'message' => Env::isDebug() ? $exception->getMessage() : null,
    ], 500);
}
