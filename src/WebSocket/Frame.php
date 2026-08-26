<?php

declare(strict_types=1);

namespace Pam\Browser\WebSocket;

final readonly class Frame
{
    public function __construct(
        public FrameType $type,
        public string $payload,
        public bool $final,
    ) {
    }
}
