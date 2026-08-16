<?php

declare(strict_types=1);

namespace RoyalSpin\Realtime;

/** One connected socket and everything the relay knows about it. */
final class Connection
{
    public string $readBuffer  = '';
    public string $writeBuffer = '';
    public string $fragmentBuffer = '';
    public bool $handshakeDone = false;
    public bool $closing       = false;

    public ?int $userId  = null;
    public ?int $matchId = null;
    public ?int $roomId  = null;
    public int $lastSeq  = 0;
    public float $lastActivity;
    public int $messageCount   = 0;
    public float $rateWindowStart;

    /** @param resource $socket */
    public function __construct(
        public readonly int $id,
        public $socket,
        public readonly string $remoteAddress,
    ) {
        $this->lastActivity    = microtime(true);
        $this->rateWindowStart = $this->lastActivity;
    }

    public function isAuthenticated(): bool
    {
        return $this->userId !== null;
    }

    /** @param array<string,mixed> $payload */
    public function send(array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        $this->writeBuffer .= Frame::encode($json);
    }

    public function sendRaw(string $bytes): void
    {
        $this->writeBuffer .= $bytes;
    }

    /**
     * Simple sliding-window throttle so a malicious client cannot spam the
     * game loop. Returns false when the message should be dropped.
     */
    public function allowMessage(int $maxPerWindow = 60, float $window = 5.0): bool
    {
        $now = microtime(true);
        if ($now - $this->rateWindowStart > $window) {
            $this->rateWindowStart = $now;
            $this->messageCount    = 0;
        }
        $this->messageCount++;
        return $this->messageCount <= $maxPerWindow;
    }
}
