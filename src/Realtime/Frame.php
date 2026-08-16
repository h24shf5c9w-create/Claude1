<?php

declare(strict_types=1);

namespace RoyalSpin\Realtime;

/**
 * RFC 6455 frame encoding/decoding.
 *
 * Only what a browser client actually sends is supported: text frames
 * (with continuation), plus the ping/pong/close control frames.
 */
final class Frame
{
    public const OP_CONTINUATION = 0x0;
    public const OP_TEXT         = 0x1;
    public const OP_BINARY       = 0x2;
    public const OP_CLOSE        = 0x8;
    public const OP_PING         = 0x9;
    public const OP_PONG         = 0xA;

    /** Server -> client frames are never masked. */
    public static function encode(string $payload, int $opcode = self::OP_TEXT): string
    {
        $length = strlen($payload);
        $header = chr(0x80 | $opcode);

        if ($length < 126) {
            $header .= chr($length);
        } elseif ($length <= 0xFFFF) {
            $header .= chr(126) . pack('n', $length);
        } else {
            $header .= chr(127) . pack('J', $length);
        }

        return $header . $payload;
    }

    /**
     * Decode as many complete frames as `$buffer` holds. Consumed bytes are
     * removed from `$buffer`; a partial frame is left for the next read.
     *
     * @return list<array{opcode:int, payload:string, fin:bool}>
     */
    public static function decode(string &$buffer): array
    {
        $frames = [];

        while (true) {
            $length = strlen($buffer);
            if ($length < 2) {
                break;
            }

            $byte0  = ord($buffer[0]);
            $byte1  = ord($buffer[1]);
            $fin    = ($byte0 & 0x80) !== 0;
            $opcode = $byte0 & 0x0F;
            $masked = ($byte1 & 0x80) !== 0;
            $len    = $byte1 & 0x7F;
            $offset = 2;

            if ($len === 126) {
                if ($length < $offset + 2) {
                    break;
                }
                $len     = unpack('n', substr($buffer, $offset, 2))[1] ?? 0;
                $offset += 2;
            } elseif ($len === 127) {
                if ($length < $offset + 8) {
                    break;
                }
                $len     = unpack('J', substr($buffer, $offset, 8))[1] ?? 0;
                $offset += 8;
            }

            // Refuse absurd frames rather than allocating on a hostile client.
            if ($len > 1_048_576) {
                $buffer = '';
                return [['opcode' => self::OP_CLOSE, 'payload' => '', 'fin' => true]];
            }

            $maskKey = '';
            if ($masked) {
                if ($length < $offset + 4) {
                    break;
                }
                $maskKey = substr($buffer, $offset, 4);
                $offset += 4;
            }

            if ($length < $offset + $len) {
                break;
            }

            $payload = substr($buffer, $offset, $len);
            if ($masked && $maskKey !== '') {
                $unmasked = '';
                for ($i = 0; $i < $len; $i++) {
                    $unmasked .= $payload[$i] ^ $maskKey[$i % 4];
                }
                $payload = $unmasked;
            }

            $buffer   = substr($buffer, $offset + $len);
            $frames[] = ['opcode' => $opcode, 'payload' => $payload, 'fin' => $fin];
        }

        return $frames;
    }

    /**
     * Build the HTTP 101 response for a valid upgrade request, or null if the
     * request is not a WebSocket handshake.
     */
    public static function handshake(string $requestHeaders): ?string
    {
        if (preg_match('/Sec-WebSocket-Key:\s*(.+)\r\n/i', $requestHeaders, $matches) !== 1) {
            return null;
        }

        $accept = base64_encode(
            pack('H*', sha1(trim($matches[1]) . '258EAFA5-E914-47DA-95CA-5AB0DC85B11C'))
        );

        return "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$accept}\r\n\r\n";
    }
}
