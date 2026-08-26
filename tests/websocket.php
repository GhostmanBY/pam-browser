<?php

declare(strict_types=1);

use Pam\Browser\Exception\ProtocolException;
use Pam\Browser\Transport\ByteStream;
use Pam\Browser\Transport\StreamConnector;
use Pam\Browser\Transport\WebSocketTransport;
use Pam\Browser\WebSocket\FrameCodec;
use Pam\Browser\WebSocket\FrameType;

final class HandshakeByteStream implements ByteStream
{
    public string $written = '';

    /** @var list<string> */
    private array $reads = [];

    /** @param list<string> $frames */
    public function __construct(private readonly array $frames)
    {
    }

    public function read(int $length, float $timeoutSeconds): string
    {
        return array_shift($this->reads) ?? '';
    }

    public function write(string $data, float $timeoutSeconds): int
    {
        $this->written .= $data;
        if (str_starts_with($data, 'GET ')) {
            preg_match('/Sec-WebSocket-Key: ([^\r]+)/', $data, $matches);
            $key = $matches[1] ?? '';
            $accept = base64_encode(sha1(
                $key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11',
                true,
            ));
            $this->reads[] = "HTTP/1.1 101 Switching Protocols\r\n"
                ."Upgrade: websocket\r\nConnection: keep-alive, Upgrade\r\n"
                ."Sec-WebSocket-Accept: {$accept}\r\n\r\n"
                .implode('', $this->frames);
        }

        return strlen($data);
    }

    public function close(): void
    {
    }
}

final readonly class FakeConnector implements StreamConnector
{
    public function __construct(public HandshakeByteStream $stream)
    {
    }

    public function connect(string $address, float $timeoutSeconds): ByteStream
    {
        assertSameValue('tcp://127.0.0.1:9222', $address, 'Connector received the wrong address.');

        return $this->stream;
    }
}

function serverFrame(FrameType $type, string $payload, bool $final = true): string
{
    $length = strlen($payload);
    if ($length > 125) {
        throw new RuntimeException('Test helper supports short frames only.');
    }

    return chr(($final ? 0x80 : 0x00) | $type->wireOpcode()).chr($length).$payload;
}

$stream = new HandshakeByteStream([
    serverFrame(FrameType::Ping, 'health'),
    serverFrame(FrameType::Text, '{"id":', false),
    serverFrame(FrameType::Continuation, '1}', true),
]);
$webSocket = WebSocketTransport::connect(
    'ws://127.0.0.1:9222/devtools/browser/test?token=one',
    new FakeConnector($stream),
);

assertSameValue('{"id":1}', $webSocket->receive(1.0), 'Fragmented text was not assembled.');
if (!str_contains($stream->written, 'GET /devtools/browser/test?token=one HTTP/1.1')) {
    throw new RuntimeException('Handshake did not preserve URL path and query.');
}

$codec = new FrameCodec();
$masked = $codec->encodeClient(FrameType::Text, 'hello', "\x01\x02\x03\x04");
assertSameValue("\x81\x85\x01\x02\x03\x04igohn", $masked, 'Client frame masking is invalid.');

$invalidStream = new HandshakeByteStream([serverFrame(FrameType::Text, "\xFF")]);
$invalidTransport = WebSocketTransport::connect(
    'ws://127.0.0.1:9222/devtools/browser/test',
    new FakeConnector($invalidStream),
);
try {
    $invalidTransport->receive(1.0);
    throw new RuntimeException('Invalid UTF-8 must be rejected.');
} catch (ProtocolException $exception) {
    if (!str_contains($exception->getMessage(), 'UTF-8')) {
        throw $exception;
    }
}

fwrite(STDOUT, "PAM Browser WebSocket tests passed.\n");
