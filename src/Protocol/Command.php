<?php

declare(strict_types=1);

namespace Pam\Browser\Protocol;

use JsonException;
use Pam\Browser\Exception\ProtocolException;

final readonly class Command
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        public int $id,
        public string $method,
        public array $parameters = [],
        public ?string $sessionId = null,
    ) {
        if ($id < 1) {
            throw new ProtocolException('A CDP command id must be positive.');
        }

        if ($method === '') {
            throw new ProtocolException('A CDP command method cannot be empty.');
        }
    }

    public function toJson(): string
    {
        $payload = ['id' => $this->id, 'method' => $this->method];

        if ($this->parameters !== []) {
            $payload['params'] = $this->parameters;
        }

        if ($this->sessionId !== null) {
            $payload['sessionId'] = $this->sessionId;
        }

        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new ProtocolException('Unable to encode the CDP command.', previous: $exception);
        }
    }
}
