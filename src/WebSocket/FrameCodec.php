<?php

declare(strict_types=1);

namespace Pam\Browser\WebSocket;

use Pam\Browser\Exception\ProtocolException;

final class FrameCodec
{
    public function __construct(private readonly int $maxPayloadBytes = 16_777_216)
    {
        if ($maxPayloadBytes < 1) {
            throw new ProtocolException('WebSocket payload limit must be positive.');
        }
    }

    public function encodeClient(FrameType $type, string $payload, ?string $mask = null): string
    {
        if ($type->isControl() && strlen($payload) > 125) {
            throw new ProtocolException('A WebSocket control frame cannot exceed 125 bytes.');
        }

        $mask ??= random_bytes(4);
        if (strlen($mask) !== 4) {
            throw new ProtocolException('A WebSocket client mask must contain four bytes.');
        }

        $length = strlen($payload);
        if ($length > $this->maxPayloadBytes) {
            throw new ProtocolException('WebSocket payload exceeds the configured byte limit.');
        }

        $header = chr(0x80 | $type->wireOpcode());
        if ($length <= 125) {
            $header .= chr(0x80 | $length);
        } elseif ($length <= 65_535) {
            $header .= chr(0x80 | 126).pack('n', $length);
        } else {
            $header .= chr(0x80 | 127).pack('N2', 0, $length);
        }

        return $header.$mask.$this->applyMask($payload, $mask);
    }

    public function decodeServer(string &$buffer): ?Frame
    {
        if (strlen($buffer) < 2) {
            return null;
        }

        $first = ord($buffer[0]);
        $second = ord($buffer[1]);
        $final = ($first & 0x80) !== 0;
        if (($first & 0x70) !== 0) {
            throw new ProtocolException('WebSocket extensions were not negotiated.');
        }
        if (($second & 0x80) !== 0) {
            throw new ProtocolException('A WebSocket server frame must not be masked.');
        }

        $type = FrameType::fromWireOpcode($first & 0x0F);
        $lengthMarker = $second & 0x7F;
        $offset = 2;

        if ($lengthMarker === 126) {
            if (strlen($buffer) < 4) {
                return null;
            }
            $decoded = unpack('nlength', substr($buffer, 2, 2));
            $length = is_array($decoded) ? $decoded['length'] : null;
            $offset = 4;
        } elseif ($lengthMarker === 127) {
            if (strlen($buffer) < 10) {
                return null;
            }
            $decoded = unpack('Nhigh/Nlow', substr($buffer, 2, 8));
            if (!is_array($decoded) || $decoded['high'] !== 0) {
                throw new ProtocolException('WebSocket payload exceeds the supported integer range.');
            }
            $length = $decoded['low'];
            $offset = 10;
        } else {
            $length = $lengthMarker;
        }

        if (!is_int($length) || $length > $this->maxPayloadBytes) {
            throw new ProtocolException('WebSocket payload exceeds the configured byte limit.');
        }
        if ($type->isControl() && (!$final || $length > 125)) {
            throw new ProtocolException('Received an invalid fragmented WebSocket control frame.');
        }
        if (strlen($buffer) < $offset + $length) {
            return null;
        }

        $payload = substr($buffer, $offset, $length);
        $buffer = substr($buffer, $offset + $length);

        return new Frame($type, $payload, $final);
    }

    private function applyMask(string $payload, string $mask): string
    {
        $masked = '';
        $length = strlen($payload);
        for ($index = 0; $index < $length; ++$index) {
            $masked .= $payload[$index] ^ $mask[$index % 4];
        }

        return $masked;
    }
}
