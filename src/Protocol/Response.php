<?php

declare(strict_types=1);

namespace Pam\Browser\Protocol;

use JsonException;
use Pam\Browser\Exception\CommandFailed;
use Pam\Browser\Exception\ProtocolException;

final readonly class Response
{
    /** @param array<string, mixed> $result */
    private function __construct(
        public int $id,
        public array $result,
        public ?string $sessionId,
    ) {
    }

    public static function fromPayload(string $payload): self
    {
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ProtocolException('Received malformed JSON from CDP.', previous: $exception);
        }

        if (!is_array($decoded) || !isset($decoded['id']) || !is_int($decoded['id'])) {
            throw new ProtocolException('The CDP response does not contain a numeric command id.');
        }

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $code = $decoded['error']['code'] ?? 0;
            $message = $decoded['error']['message'] ?? 'Unknown CDP error';

            throw new CommandFailed(
                $decoded['id'],
                is_int($code) ? $code : 0,
                is_string($message) ? $message : 'Unknown CDP error',
            );
        }

        $rawResult = $decoded['result'] ?? [];
        if (!is_array($rawResult)) {
            throw new ProtocolException('The CDP command result must be an object.');
        }
        $result = self::stringKeyed($rawResult);

        $sessionId = $decoded['sessionId'] ?? null;

        return new self(
            $decoded['id'],
            $result,
            is_string($sessionId) ? $sessionId : null,
        );
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $values): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new ProtocolException('The CDP command result must be a JSON object.');
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
