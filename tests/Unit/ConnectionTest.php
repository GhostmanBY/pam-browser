<?php

declare(strict_types=1);

namespace Pam\Browser\Tests\Unit;

use Pam\Browser\Connection;
use Pam\Browser\Page;
use Pam\Browser\Exception\CommandFailed;
use Pam\Browser\Protocol\Event;
use Pam\Browser\Transport\Transport;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase
{
    public function testItRoutesAnInterleavedEventAndReturnsTheCommandResult(): void
    {
        $transport = new ConnectionFakeTransport([
            '{"method":"Runtime.consoleAPICalled","params":{"type":"log"}}',
            '{"id":1,"result":{"product":"Chrome/151"}}',
        ]);
        $connection = new Connection($transport);
        $eventType = null;
        $connection->on(
            'Runtime.consoleAPICalled',
            static function (Event $event) use (&$eventType): void {
                $eventType = $event->parameters['type'] ?? null;
            },
        );

        $result = $connection->command('Browser.getVersion');

        self::assertSame('Chrome/151', $result['product']);
        self::assertSame('log', $eventType);
        self::assertSame('{"id":1,"method":"Browser.getVersion"}', $transport->sent[0]);
    }

    public function testItSupportsACommandIssuedFromAnEventListener(): void
    {
        $transport = new ConnectionFakeTransport([
            '{"method":"Runtime.bindingCalled","params":{"name":"bridge"}}',
            '{"id":1,"result":{"outer":true}}',
            '{"id":2,"result":{"inner":true}}',
        ]);
        $connection = new Connection($transport);
        $inner = null;
        $connection->on('Runtime.bindingCalled', static function () use ($connection, &$inner): void {
            $inner = $connection->command('Runtime.evaluate');
        });

        $outer = $connection->command('Page.navigate');

        self::assertSame(['outer' => true], $outer);
        self::assertSame(['inner' => true], $inner);
        self::assertCount(2, $transport->sent);
    }

    public function testItPreservesCdpErrors(): void
    {
        $connection = new Connection(new ConnectionFakeTransport([
            '{"id":1,"error":{"code":-32601,"message":"Method not found"}}',
        ]));

        try {
            $connection->command('Unknown.method');
            self::fail('Expected a CDP command error.');
        } catch (CommandFailed $exception) {
            self::assertSame(-32601, $exception->protocolCode);
            self::assertSame('Method not found', $exception->protocolMessage);
        }
    }

    public function testItWaitsForAnEventMatchingTheSessionPredicate(): void
    {
        $connection = new Connection(new ConnectionFakeTransport([
            '{"method":"Page.loadEventFired","params":{},"sessionId":"other"}',
            '{"method":"Page.loadEventFired","params":{},"sessionId":"wanted"}',
        ]));

        $event = $connection->waitForEvent(
            'Page.loadEventFired',
            static fn (Event $candidate): bool => $candidate->sessionId === 'wanted',
            1.0,
        );

        self::assertSame('wanted', $event->sessionId);
    }

    public function testPageCanFulfillTheMainDocumentFromCache(): void
    {
        $transport = new ConnectionFakeTransport([
            '{"id":1,"result":{}}',
            '{"method":"Fetch.requestPaused","sessionId":"session","params":{"requestId":"request","resourceType":"Document","request":{"url":"https://web.whatsapp.com/"}}}',
            '{"id":2,"result":{}}',
        ]);
        $page = new Page(new Connection($transport), 'target', 'session');
        $page->serveDocument('https://web.whatsapp.com/', '<html>cached</html>');

        self::assertTrue($page->pump());
        self::assertStringContainsString('Fetch.enable', $transport->sent[0]);
        self::assertStringContainsString('Fetch.fulfillRequest', $transport->sent[1]);
        self::assertStringContainsString(base64_encode('<html>cached</html>'), $transport->sent[1]);
    }

    public function testPageCanCaptureTheMainDocumentResponse(): void
    {
        $transport = new ConnectionFakeTransport([
            '{"id":1,"result":{}}',
            '{"method":"Network.responseReceived","sessionId":"session","params":{"requestId":"request","response":{"url":"https://web.whatsapp.com/"}}}',
            '{"method":"Network.loadingFinished","sessionId":"session","params":{"requestId":"request"}}',
            '{"id":2,"result":{"body":"PGh0bWw+bGl2ZTwvaHRtbD4=","base64Encoded":true}}',
        ]);
        $page = new Page(new Connection($transport), 'target', 'session');
        $html = null;
        $page->captureDocument('https://web.whatsapp.com/', static function (string $body) use (&$html): void {
            $html = $body;
        });

        self::assertTrue($page->pump());
        self::assertTrue($page->pump());
        self::assertSame('<html>live</html>', $html);
        self::assertStringContainsString('Network.getResponseBody', $transport->sent[1]);
    }

    public function testPageCapturesAndValidatesAPngScreenshot(): void
    {
        $png = "\x89PNG\r\n\x1a\ncertification";
        $transport = new ConnectionFakeTransport([
            json_encode(['id' => 1, 'result' => ['data' => base64_encode($png)]], JSON_THROW_ON_ERROR),
        ]);
        $page = new Page(new Connection($transport), 'target', 'session');

        self::assertSame($png, $page->captureScreenshot());
        self::assertStringContainsString('Page.captureScreenshot', $transport->sent[0]);
        self::assertStringContainsString('"format":"png"', $transport->sent[0]);
    }

    public function testProxyAuthenticationAndCachedDocumentShareFetchRouting(): void
    {
        $transport = new ConnectionFakeTransport([
            '{"id":1,"result":{}}',
            '{"id":2,"result":{}}',
            '{"method":"Fetch.requestPaused","sessionId":"session","params":{"requestId":"request","resourceType":"Document","request":{"url":"https://web.whatsapp.com/"}}}',
            '{"id":3,"result":{}}',
        ]);
        $page = new Page(new Connection($transport), 'target', 'session');
        $page->authenticate('proxy-user', 'secret');
        $page->serveDocument('https://web.whatsapp.com/', '<html>cached</html>');

        self::assertTrue($page->pump());
        self::assertCount(3, $transport->sent);
        self::assertStringContainsString('"handleAuthRequests":true', $transport->sent[1]);
        self::assertStringContainsString('Fetch.fulfillRequest', $transport->sent[2]);
    }
}

final class ConnectionFakeTransport implements Transport
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
