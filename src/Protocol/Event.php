<?php

declare(strict_types=1);

namespace Pam\Browser\Protocol;

final readonly class Event
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        public string $method,
        public array $parameters,
        public ?string $sessionId,
    ) {
    }
}
