<?php

declare(strict_types=1);

namespace Pam\Browser\WebSocket;

use Pam\Browser\Exception\ProtocolException;

enum FrameType: int
{
    case Text = 1;
    case Continuation = 2;
    case Close = 3;
    case Ping = 4;
    case Pong = 5;

    public static function fromWireOpcode(int $opcode): self
    {
        return match ($opcode) {
            0x0 => self::Continuation,
            0x1 => self::Text,
            0x8 => self::Close,
            0x9 => self::Ping,
            0xA => self::Pong,
            default => throw new ProtocolException(sprintf(
                'Unsupported WebSocket opcode 0x%X.',
                $opcode,
            )),
        };
    }

    /** @return int<0, 255> */
    public function wireOpcode(): int
    {
        return match ($this) {
            self::Continuation => 0x0,
            self::Text => 0x1,
            self::Close => 0x8,
            self::Ping => 0x9,
            self::Pong => 0xA,
        };
    }

    public function isControl(): bool
    {
        return match ($this) {
            self::Close, self::Ping, self::Pong => true,
            self::Text, self::Continuation => false,
        };
    }
}
