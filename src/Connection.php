<?php

declare(strict_types=1);

namespace Pam\Browser;

use JsonException;
use Pam\Browser\Exception\ProtocolException;
use Pam\Browser\Protocol\Command;
use Pam\Browser\Protocol\Event;
use Pam\Browser\Protocol\Response;
use Pam\Browser\Transport\Transport;

final class Connection
{
    private const MAX_QUEUED_EVENTS_PER_METHOD = 256;
    private const MAX_PENDING_RESPONSES = 256;

    private int $nextCommandId = 1;

    /** @var array<string, list<callable(Event): void>> */
    private array $listeners = [];

    /** @var array<string, list<Event>> */
    private array $eventQueue = [];

    /** @var array<int, string> */
    private array $pendingResponses = [];

    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function command(
        string $method,
        array $parameters = [],
        ?string $sessionId = null,
        float $timeoutSeconds = 30.0,
    ): array {
        $command = new Command($this->nextCommandId++, $method, $parameters, $sessionId);
        $this->transport->send($command->toJson());
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $pending = $this->pendingResponses[$command->id] ?? null;
            if ($pending !== null) {
                unset($this->pendingResponses[$command->id]);

                return Response::fromPayload($pending)->result;
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }

            $payload = $this->transport->receive($remaining);
            if ($payload === null) {
                break;
            }

            $messageId = $this->messageId($payload);
            if ($messageId === null) {
                $this->dispatchEvent($payload);
                continue;
            }

            if ($messageId !== $command->id) {
                $this->queueResponse($messageId, $payload);
                continue;
            }

            return Response::fromPayload($payload)->result;
        } while (true);

        throw new ProtocolException(sprintf(
            'Timed out waiting for CDP command %d (%s).',
            $command->id,
            $command->method,
        ));
    }

    /** @param callable(Event): void $listener */
    public function on(string $method, callable $listener): void
    {
        $this->listeners[$method][] = $listener;
    }

    public function pump(float $timeoutSeconds = 0.0): bool
    {
        $payload = $this->transport->receive($timeoutSeconds);
        if ($payload === null) {
            return false;
        }

        $messageId = $this->messageId($payload);
        if ($messageId !== null) {
            $this->queueResponse($messageId, $payload);

            return true;
        }

        $this->dispatchEvent($payload);

        return true;
    }

    /** @param null|callable(Event): bool $predicate */
    public function waitForEvent(
        string $method,
        ?callable $predicate = null,
        float $timeoutSeconds = 30.0,
    ): Event {
        if ($method === '' || $timeoutSeconds <= 0.0) {
            throw new ProtocolException('An event method and positive timeout are required.');
        }

        $deadline = microtime(true) + $timeoutSeconds;
        do {
            foreach ($this->eventQueue[$method] ?? [] as $index => $event) {
                if ($predicate === null || $predicate($event)) {
                    array_splice($this->eventQueue[$method], $index, 1);

                    return $event;
                }
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.0 || !$this->pump($remaining)) {
                break;
            }
        } while (true);

        throw new ProtocolException(sprintf('Timed out waiting for CDP event %s.', $method));
    }

    public function close(): void
    {
        $this->transport->close();
    }

    private function messageId(string $payload): ?int
    {
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ProtocolException('Received malformed JSON from CDP.', previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new ProtocolException('A CDP message must be a JSON object.');
        }

        return isset($decoded['id']) && is_int($decoded['id']) ? $decoded['id'] : null;
    }

    private function dispatchEvent(string $payload): void
    {
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ProtocolException('Received malformed JSON from CDP.', previous: $exception);
        }

        if (!is_array($decoded) || !isset($decoded['method']) || !is_string($decoded['method'])) {
            throw new ProtocolException('The CDP event does not contain a method.');
        }

        $rawParameters = $decoded['params'] ?? [];
        if (!is_array($rawParameters)) {
            throw new ProtocolException('The CDP event parameters must be an object.');
        }
        $parameters = $this->stringKeyed($rawParameters, 'CDP event parameters');

        $sessionId = $decoded['sessionId'] ?? null;
        $event = new Event(
            $decoded['method'],
            $parameters,
            is_string($sessionId) ? $sessionId : null,
        );

        $this->eventQueue[$event->method][] = $event;
        if (count($this->eventQueue[$event->method]) > self::MAX_QUEUED_EVENTS_PER_METHOD) {
            array_shift($this->eventQueue[$event->method]);
        }

        foreach ($this->listeners[$event->method] ?? [] as $listener) {
            $listener($event);
        }
    }

    private function queueResponse(int $messageId, string $payload): void
    {
        $this->pendingResponses[$messageId] = $payload;
        if (count($this->pendingResponses) > self::MAX_PENDING_RESPONSES) {
            array_shift($this->pendingResponses);
        }
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<string, mixed>
     */
    private function stringKeyed(array $values, string $label): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new ProtocolException($label.' must be a JSON object.');
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
