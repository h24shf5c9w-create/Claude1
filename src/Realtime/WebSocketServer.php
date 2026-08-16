<?php

declare(strict_types=1);

namespace RoyalSpin\Realtime;

use RoyalSpin\Auth\AuthService;
use RoyalSpin\Game\GameService;
use RoyalSpin\Game\RoomService;
use RoyalSpin\Support\Config;
use RoyalSpin\Support\Db;
use Throwable;

/**
 * Single-process, non-blocking WebSocket relay.
 *
 * Responsibilities — deliberately narrow:
 *   1. authenticate sockets (single-use ticket minted over HTTPS)
 *   2. accept action intents and hand them to GameService (the authority)
 *   3. tail the `game_events` outbox and fan events out to subscribers
 *   4. keep presence fresh and unstick turns abandoned by offline players
 *
 * It contains no game rules of its own. Anything it "decides" would be a bug.
 */
final class WebSocketServer
{
    /** @var array<int,Connection> */
    private array $connections = [];

    /** @var array<int,int> stream resource id => connection id (O(1) lookup per tick) */
    private array $socketIndex = [];

    /** @var array<int,array<int,true>> matchId => set of connection ids */
    private array $matchSubscribers = [];

    /** @var array<int,array<int,true>> roomId => set of connection ids */
    private array $lobbySubscribers = [];

    /** @var array<int,string> roomId => hash of last broadcast lobby state */
    private array $lobbyHashes = [];

    private int $nextConnectionId = 1;
    private int $lastEventId      = 0;
    private float $lastMaintenance = 0.0;
    private bool $running          = true;

    /** @var resource|null */
    private $server = null;

    public function __construct(
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 8081,
        private readonly GameService $game = new GameService(),
        private readonly RoomService $rooms = new RoomService(),
    ) {
    }

    public function run(): void
    {
        $address = sprintf('tcp://%s:%d', $this->host, $this->port);
        $context = stream_context_create(['socket' => ['backlog' => 128, 'so_reuseaddr' => true]]);

        $server = @stream_socket_server($address, $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        if ($server === false) {
            fwrite(STDERR, "Could not bind {$address}: {$errorMessage} ({$errorCode})\n");
            exit(1);
        }
        stream_set_blocking($server, false);
        $this->server = $server;

        $this->lastEventId = EventBus::latestId();
        $this->log("Royal Spin realtime service listening on {$address}");
        $this->log('Resuming event tail at id ' . $this->lastEventId);

        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, fn () => $this->shutdown());
            pcntl_signal(SIGTERM, fn () => $this->shutdown());
        }

        while ($this->running) {
            $this->tick();
        }

        foreach ($this->connections as $connection) {
            $this->disconnect($connection, 'server shutting down');
        }
        fclose($server);
        $this->log('Stopped.');
    }

    private function shutdown(): void
    {
        $this->running = false;
    }

    /* ================================================================== */
    /*  Event loop                                                        */
    /* ================================================================== */

    private function tick(): void
    {
        $read   = [$this->server];
        $write  = [];
        $except = null;

        foreach ($this->connections as $connection) {
            $read[] = $connection->socket;
            if ($connection->writeBuffer !== '') {
                $write[] = $connection->socket;
            }
        }

        // 200 ms wake-up: fast enough that a relayed spin feels instant,
        // cheap enough to idle at ~0% CPU.
        $ready = @stream_select($read, $write, $except, 0, 200_000);
        if ($ready === false) {
            return;
        }

        foreach ($read as $socket) {
            if ($socket === $this->server) {
                $this->accept();
                continue;
            }
            $connection = $this->connectionFor($socket);
            if ($connection !== null) {
                $this->readFrom($connection);
            }
        }

        foreach ($write as $socket) {
            $connection = $this->connectionFor($socket);
            if ($connection !== null) {
                $this->flush($connection);
            }
        }

        $this->relayEvents();
        $this->maintenance();
    }

    private function accept(): void
    {
        while (true) {
            $socket = @stream_socket_accept($this->server, 0, $peer);
            if ($socket === false) {
                return;
            }
            stream_set_blocking($socket, false);

            $connection = new Connection($this->nextConnectionId++, $socket, is_string($peer) ? $peer : 'unknown');
            $this->connections[$connection->id]     = $connection;
            $this->socketIndex[(int) $socket]       = $connection->id;

            if (count($this->connections) > 2000) {
                $this->disconnect($connection, 'server at capacity');
            }
        }
    }

    /** @param resource $socket */
    private function connectionFor($socket): ?Connection
    {
        $connectionId = $this->socketIndex[(int) $socket] ?? null;
        return $connectionId === null ? null : ($this->connections[$connectionId] ?? null);
    }

    private function readFrom(Connection $connection): void
    {
        $data = @fread($connection->socket, 65535);
        if ($data === false || $data === '') {
            if (feof($connection->socket)) {
                $this->disconnect($connection, 'peer closed');
            }
            return;
        }

        $connection->readBuffer  .= $data;
        $connection->lastActivity = microtime(true);

        if (!$connection->handshakeDone) {
            if (!str_contains($connection->readBuffer, "\r\n\r\n")) {
                if (strlen($connection->readBuffer) > 16384) {
                    $this->disconnect($connection, 'oversized handshake');
                }
                return;
            }

            [$headers, $rest]        = explode("\r\n\r\n", $connection->readBuffer, 2);
            $response                = Frame::handshake($headers . "\r\n\r\n");
            if ($response === null) {
                $this->writeImmediately($connection, "HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n");
                $this->disconnect($connection, 'not a websocket handshake');
                return;
            }

            $connection->sendRaw($response);
            $connection->handshakeDone = true;
            $connection->readBuffer    = $rest;
            $this->flush($connection);
        }

        foreach (Frame::decode($connection->readBuffer) as $frame) {
            $this->handleFrame($connection, $frame);
        }
    }

    /** @param array{opcode:int, payload:string, fin:bool} $frame */
    private function handleFrame(Connection $connection, array $frame): void
    {
        switch ($frame['opcode']) {
            case Frame::OP_CLOSE:
                $connection->sendRaw(Frame::encode('', Frame::OP_CLOSE));
                $this->flush($connection);
                $this->disconnect($connection, 'client closed');
                return;

            case Frame::OP_PING:
                $connection->sendRaw(Frame::encode($frame['payload'], Frame::OP_PONG));
                return;

            case Frame::OP_PONG:
                return;

            case Frame::OP_CONTINUATION:
                $connection->fragmentBuffer .= $frame['payload'];
                if (!$frame['fin']) {
                    return;
                }
                $payload                    = $connection->fragmentBuffer;
                $connection->fragmentBuffer = '';
                break;

            case Frame::OP_TEXT:
                if (!$frame['fin']) {
                    $connection->fragmentBuffer = $frame['payload'];
                    return;
                }
                $payload = $frame['payload'];
                break;

            default:
                return;
        }

        if (!$connection->allowMessage()) {
            $connection->send(['type' => 'error', 'error' => 'Slow down — too many requests.']);
            return;
        }

        $message = json_decode($payload, true);
        if (!is_array($message) || !isset($message['type']) || !is_string($message['type'])) {
            $connection->send(['type' => 'error', 'error' => 'Malformed message.']);
            return;
        }

        try {
            $this->handleMessage($connection, $message);
        } catch (Throwable $exception) {
            $this->log('message error: ' . $exception->getMessage());
            $connection->send(['type' => 'error', 'error' => 'Something went wrong handling that action.']);
        }
    }

    /* ================================================================== */
    /*  Protocol                                                          */
    /* ================================================================== */

    /** @param array<string,mixed> $message */
    private function handleMessage(Connection $connection, array $message): void
    {
        $type      = (string) $message['type'];
        $requestId = isset($message['request_id']) && is_scalar($message['request_id'])
            ? (string) $message['request_id']
            : null;

        // --- authentication ------------------------------------------------
        if ($type === 'auth') {
            $ticket = isset($message['ticket']) && is_string($message['ticket']) ? $message['ticket'] : '';
            $userId = AuthService::redeemWebSocketTicket($ticket);
            if ($userId === null) {
                $connection->send(['type' => 'auth_error', 'error' => 'Authentication failed.']);
                $this->disconnect($connection, 'bad ticket');
                return;
            }
            $connection->userId = $userId;
            $connection->send(['type' => 'authenticated', 'user_id' => $userId]);
            return;
        }

        if (!$connection->isAuthenticated()) {
            $connection->send(['type' => 'auth_error', 'error' => 'Not authenticated.']);
            return;
        }

        $userId = (int) $connection->userId;

        switch ($type) {
            case 'subscribe':
                $matchId = isset($message['match_id']) ? (int) $message['match_id'] : 0;
                if ($matchId <= 0 || !$this->game->isParticipant($matchId, $userId)) {
                    $connection->send(['type' => 'error', 'error' => 'You are not part of that match.']);
                    return;
                }
                $this->subscribeToMatch($connection, $matchId);
                return;

            case 'lobby_subscribe':
                $roomId = isset($message['room_id']) ? (int) $message['room_id'] : 0;
                $member = Db::first(
                    'SELECT id FROM room_players WHERE room_id = :room_id AND user_id = :user_id',
                    ['room_id' => $roomId, 'user_id' => $userId]
                );
                if ($roomId <= 0 || $member === null) {
                    $connection->send(['type' => 'error', 'error' => 'You are not in that room.']);
                    return;
                }
                $connection->roomId                        = $roomId;
                $this->lobbySubscribers[$roomId][$connection->id] = true;
                $connection->send(['type' => 'lobby_state', 'room' => $this->rooms->lobbyState($roomId)]);
                return;

            case 'ping':
                $connection->send(['type' => 'pong', 'time' => time()]);
                if ($connection->matchId !== null) {
                    $this->game->setConnectionStatus($connection->matchId, $userId, 'online');
                    $this->game->touch($connection->matchId, $userId);
                }
                return;

            case 'request_state':
                if ($connection->matchId === null) {
                    $connection->send(['type' => 'error', 'error' => 'Subscribe to a match first.']);
                    return;
                }
                $this->sendState($connection);
                return;

            case 'roll_dice':
            case 'reroll_dice':
            case 'spin':
            case 'buy_upgrade':
            case 'end_turn':
                $this->handleAction($connection, $type, $message, $requestId);
                return;

            default:
                $connection->send(['type' => 'error', 'error' => 'Unknown message type.']);
        }
    }

    /** @param array<string,mixed> $message */
    private function handleAction(Connection $connection, string $action, array $message, ?string $requestId): void
    {
        if ($connection->matchId === null) {
            $connection->send(['type' => 'error', 'error' => 'Subscribe to a match first.', 'request_id' => $requestId]);
            return;
        }

        $userId   = (int) $connection->userId;
        $matchId  = $connection->matchId;
        $actionId = isset($message['action_id']) && is_string($message['action_id']) ? $message['action_id'] : null;

        $result = match ($action) {
            'roll_dice'   => $this->game->rollDice($userId, $matchId, $actionId, false),
            'reroll_dice' => $this->game->rollDice($userId, $matchId, $actionId, true),
            'spin'        => $this->game->spin($userId, $matchId, $actionId),
            'buy_upgrade' => $this->game->buyUpgrade(
                $userId,
                $matchId,
                isset($message['upgrade_key']) && is_string($message['upgrade_key']) ? $message['upgrade_key'] : '',
                $actionId
            ),
            'end_turn'    => $this->game->endTurn($userId, $matchId, $actionId),
            default       => ['ok' => false, 'error' => 'Unknown action.'],
        };

        $connection->send([
            'type'       => 'action_result',
            'action'     => $action,
            'request_id' => $requestId,
            'result'     => $result,
        ]);
    }

    private function subscribeToMatch(Connection $connection, int $matchId): void
    {
        if ($connection->matchId !== null && $connection->matchId !== $matchId) {
            unset($this->matchSubscribers[$connection->matchId][$connection->id]);
        }

        $connection->matchId                              = $matchId;
        $this->matchSubscribers[$matchId][$connection->id] = true;

        $this->game->setConnectionStatus($matchId, (int) $connection->userId, 'online');
        $this->sendState($connection);
    }

    /**
     * Push the full authoritative state. Sent on subscribe and after every
     * reconnect — the client always rebuilds from this rather than trusting
     * whatever it had in memory.
     */
    private function sendState(Connection $connection): void
    {
        if ($connection->matchId === null || $connection->userId === null) {
            return;
        }
        $state = $this->game->state($connection->matchId, $connection->userId);
        if ($state === null) {
            $connection->send(['type' => 'error', 'error' => 'Match not found.']);
            return;
        }
        $connection->lastSeq = (int) $state['match']['seq'];
        $connection->send(['type' => 'state', 'state' => $state]);
    }

    /* ================================================================== */
    /*  Outbox relay                                                      */
    /* ================================================================== */

    private function relayEvents(): void
    {
        // If the newest id is behind our cursor the table was reset underneath
        // us (a restore, a wipe, `migrate --fresh`). Without this the relay
        // would silently ignore every future event, so rewind instead.
        $newestId = EventBus::latestId();
        if ($newestId < $this->lastEventId) {
            $this->log("event table reset detected (id {$newestId} < {$this->lastEventId}) — rewinding");
            $this->lastEventId = 0;
            foreach ($this->connections as $connection) {
                if ($connection->matchId !== null) {
                    $this->sendState($connection);
                }
            }
        }

        $events = EventBus::tail($this->lastEventId, 500);
        if ($events === []) {
            return;
        }

        foreach ($events as $event) {
            $this->lastEventId = $event['id'];

            $subscribers = $this->matchSubscribers[$event['match_id']] ?? [];
            if ($subscribers === []) {
                continue;
            }

            $payload    = $event['payload'];
            $privateTo  = isset($payload['private_to']) ? (int) $payload['private_to'] : null;
            unset($payload['private_to']);

            $frame = [
                'type'     => 'event',
                'event'    => $event['type'],
                'seq'      => $event['seq'],
                'match_id' => $event['match_id'],
                'payload'  => $payload,
            ];

            foreach (array_keys($subscribers) as $connectionId) {
                $connection = $this->connections[$connectionId] ?? null;
                if ($connection === null) {
                    unset($this->matchSubscribers[$event['match_id']][$connectionId]);
                    continue;
                }
                if ($privateTo !== null && $connection->userId !== $privateTo) {
                    continue;
                }
                $connection->lastSeq = max($connection->lastSeq, $event['seq']);
                $connection->send($frame);
            }
        }
    }

    /* ================================================================== */
    /*  Periodic maintenance                                              */
    /* ================================================================== */

    private function maintenance(): void
    {
        $now = microtime(true);
        if ($now - $this->lastMaintenance < 3.0) {
            return;
        }
        $this->lastMaintenance = $now;

        if (!Db::healthy()) {
            $this->log('database connection lost — reconnecting');
            Db::reset();
            try {
                Db::connection();
            } catch (Throwable $exception) {
                $this->log('reconnect failed: ' . $exception->getMessage());
                return;
            }
        }

        try {
            // 1. presence: heartbeat lapsed -> mark offline (never remove).
            $this->game->reapStalePresence();

            // 2. a match must never be stuck behind one dropped phone.
            foreach (array_keys($this->matchSubscribers) as $matchId) {
                $this->game->skipStalledTurn($matchId);
            }

            // 3. lobbies are not backed by the event outbox (no match id yet),
            //    so push them whenever their rendered state actually changes.
            $this->broadcastLobbyChanges();
        } catch (Throwable $exception) {
            $this->log('maintenance error: ' . $exception->getMessage());
        }

        // 4. idle sockets.
        $timeout = Config::int('game.connection.offline_after_seconds', 25) * 4;
        foreach ($this->connections as $connection) {
            if ($now - $connection->lastActivity > $timeout) {
                $this->disconnect($connection, 'idle timeout');
            }
        }
    }

    private function broadcastLobbyChanges(): void
    {
        foreach ($this->lobbySubscribers as $roomId => $subscribers) {
            if ($subscribers === []) {
                unset($this->lobbySubscribers[$roomId], $this->lobbyHashes[$roomId]);
                continue;
            }

            $room = $this->rooms->lobbyState($roomId);
            $hash = md5((string) json_encode($room));
            if (($this->lobbyHashes[$roomId] ?? null) === $hash) {
                continue;
            }
            $this->lobbyHashes[$roomId] = $hash;

            $frame = ['type' => 'lobby_state', 'room' => $room];
            foreach (array_keys($subscribers) as $connectionId) {
                $connection = $this->connections[$connectionId] ?? null;
                if ($connection === null) {
                    unset($this->lobbySubscribers[$roomId][$connectionId]);
                    continue;
                }
                $connection->send($frame);
            }
        }
    }

    /* ================================================================== */
    /*  Socket plumbing                                                   */
    /* ================================================================== */

    private function flush(Connection $connection): void
    {
        if ($connection->writeBuffer === '') {
            return;
        }
        $written = @fwrite($connection->socket, $connection->writeBuffer);
        if ($written === false) {
            $this->disconnect($connection, 'write failed');
            return;
        }
        $connection->writeBuffer = substr($connection->writeBuffer, $written);
    }

    private function writeImmediately(Connection $connection, string $bytes): void
    {
        @fwrite($connection->socket, $bytes);
    }

    private function disconnect(Connection $connection, string $reason): void
    {
        // Drain anything still queued (an auth_error, a close frame) before the
        // socket goes away, otherwise the client sees a silent drop and cannot
        // tell a rejected ticket apart from a network failure.
        if ($connection->writeBuffer !== '' && is_resource($connection->socket)) {
            for ($attempt = 0; $attempt < 3 && $connection->writeBuffer !== ''; $attempt++) {
                $written = @fwrite($connection->socket, $connection->writeBuffer);
                if ($written === false || $written === 0) {
                    break;
                }
                $connection->writeBuffer = substr($connection->writeBuffer, $written);
            }
        }

        if ($connection->matchId !== null) {
            unset($this->matchSubscribers[$connection->matchId][$connection->id]);

            // Only flip to offline when this account has no other live socket
            // (two tabs, or a reconnect that overlapped the old connection).
            if ($connection->userId !== null && !$this->hasOtherConnection($connection)) {
                try {
                    $this->game->setConnectionStatus($connection->matchId, $connection->userId, 'offline');
                } catch (Throwable $exception) {
                    $this->log('presence update failed: ' . $exception->getMessage());
                }
            }
        }
        if ($connection->roomId !== null) {
            unset($this->lobbySubscribers[$connection->roomId][$connection->id]);
        }

        unset($this->connections[$connection->id], $this->socketIndex[(int) $connection->socket]);
        if (is_resource($connection->socket)) {
            @fclose($connection->socket);
        }
        unset($reason);
    }

    private function hasOtherConnection(Connection $connection): bool
    {
        foreach ($this->connections as $other) {
            if ($other->id !== $connection->id
                && $other->userId === $connection->userId
                && $other->matchId === $connection->matchId
            ) {
                return true;
            }
        }
        return false;
    }

    private function log(string $message): void
    {
        fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
    }
}
