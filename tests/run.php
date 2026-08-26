<?php

declare(strict_types=1);

use Pam\Browser\Connection;
use Pam\Browser\Exception\CommandFailed;
use Pam\Browser\Protocol\Event;
use Pam\Browser\Transport\Transport;

require __DIR__.'/bootstrap.php';

/** @param mixed $expected @param mixed $actual */
function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

final class FakeTransport implements Transport
{
    /** @var list<string> */
    public array $sent = [];

    /** @param list<string> $incoming */
    public function __construct(private array $incoming)
    {
    }

    public function send(string $payload): void
    {
        $this->sent[] = $payload;
    }

    public function receive(float $timeoutSeconds): ?string
    {
        return array_shift($this->incoming);
    }

    public function close(): void
    {
    }
}

$transport = new FakeTransport([
    '{"method":"Runtime.consoleAPICalled","params":{"type":"log"}}',
    '{"id":1,"result":{"product":"Chrome/140"}}',
]);
$connection = new Connection($transport);
$eventType = null;
$connection->on('Runtime.consoleAPICalled', static function (Event $event) use (&$eventType): void {
    $eventType = $event->parameters['type'] ?? null;
});
$result = $connection->command('Browser.getVersion');

assertSameValue('Chrome/140', $result['product'] ?? null, 'Command result was not decoded.');
assertSameValue('log', $eventType, 'Interleaved event was not dispatched.');
assertSameValue('{"id":1,"method":"Browser.getVersion"}', $transport->sent[0] ?? null, 'Command was not encoded.');

$failed = new Connection(new FakeTransport([
    '{"id":1,"error":{"code":-32601,"message":"Method not found"}}',
]));

try {
    $failed->command('Unknown.method');
    throw new RuntimeException('CDP errors must throw CommandFailed.');
} catch (CommandFailed $exception) {
    assertSameValue(-32601, $exception->protocolCode, 'CDP error code was not preserved.');
}

$pumpTransport = new FakeTransport([
    '{"method":"Page.loadEventFired","params":{"timestamp":12.5},"sessionId":"page-1"}',
]);
$pumpConnection = new Connection($pumpTransport);
$sessionId = null;
$pumpConnection->on('Page.loadEventFired', static function (Event $event) use (&$sessionId): void {
    $sessionId = $event->sessionId;
});

assertSameValue(true, $pumpConnection->pump(0.1), 'Event pump did not consume an event.');
assertSameValue('page-1', $sessionId, 'Event session id was not preserved.');

$queuedTransport = new FakeTransport([
    '{"method":"Page.loadEventFired","params":{"timestamp":1},"sessionId":"other"}',
    '{"method":"Page.loadEventFired","params":{"timestamp":2},"sessionId":"wanted"}',
]);
$queuedConnection = new Connection($queuedTransport);
$matched = $queuedConnection->waitForEvent(
    'Page.loadEventFired',
    static fn (Event $event): bool => $event->sessionId === 'wanted',
    1.0,
);
assertSameValue('wanted', $matched->sessionId, 'Event wait predicate selected the wrong event.');

fwrite(STDOUT, "PAM Browser protocol tests passed.\n");

require __DIR__.'/websocket.php';
