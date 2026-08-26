<?php

declare(strict_types=1);

namespace Pam\Browser\Transport;

use Pam\Browser\Exception\ProtocolException;
use Pam\Browser\WebSocket\FrameCodec;
use Pam\Browser\WebSocket\FrameType;

final class WebSocketTransport implements Transport
{
    private const ACCEPT_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    private string $buffer = '';
    private string $fragment = '';
    private bool $fragmenting = false;
    private bool $closed = false;

    private function __construct(
        private readonly ByteStream $stream,
        private readonly FrameCodec $codec,
        private readonly float $writeTimeoutSeconds,
        private readonly int $maxPayloadBytes,
    ) {
    }

    public static function connect(
        string $url,
        ?StreamConnector $connector = null,
        float $timeoutSeconds = 10.0,
        int $maxPayloadBytes = 16_777_216,
    ): self {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'ws') {
            throw new ProtocolException('CDP requires a valid local ws:// WebSocket URL.');
        }

        $host = $parts['host'] ?? null;
        $port = $parts['port'] ?? 80;
        if (!is_string($host) || $host === '') {
            throw new ProtocolException('WebSocket URL must contain a host and valid port.');
        }

        $path = is_string($parts['path'] ?? null) ? $parts['path'] : '/';
        if (isset($parts['query'])) {
            $path .= '?'.$parts['query'];
        }

        $connector ??= new PamStreamConnector();
        $addressHost = str_contains($host, ':') ? '['.$host.']' : $host;
        $stream = $connector->connect(sprintf('tcp://%s:%d', $addressHost, $port), $timeoutSeconds);
        $key = base64_encode(random_bytes(16));
        $request = implode("\r\n", [
            sprintf('GET %s HTTP/1.1', $path),
            sprintf('Host: %s:%d', $host, $port),
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: '.$key,
            'Sec-WebSocket-Version: 13',
            '',
            '',
        ]);

        try {
            $stream->write($request, $timeoutSeconds);
            [$response, $remaining] = self::readHandshake($stream, $timeoutSeconds);
            self::validateHandshake($response, $key);
        } catch (\Throwable $exception) {
            $stream->close();
            throw $exception;
        }

        $transport = new self(
            $stream,
            new FrameCodec($maxPayloadBytes),
            $timeoutSeconds,
            $maxPayloadBytes,
        );
        $transport->buffer = $remaining;

        return $transport;
    }

    public function send(string $payload): void
    {
        $this->assertOpen();
        $this->stream->write(
            $this->codec->encodeClient(FrameType::Text, $payload),
            $this->writeTimeoutSeconds,
        );
    }

    public function receive(float $timeoutSeconds): ?string
    {
        $this->assertOpen();
        $deadline = microtime(true) + max(0.0, $timeoutSeconds);

        while (true) {
            $frame = $this->codec->decodeServer($this->buffer);
            if ($frame !== null) {
                if ($frame->type === FrameType::Ping) {
                    $this->stream->write(
                        $this->codec->encodeClient(FrameType::Pong, $frame->payload),
                        $this->writeTimeoutSeconds,
                    );
                    continue;
                }
                if ($frame->type === FrameType::Pong) {
                    continue;
                }
                if ($frame->type === FrameType::Close) {
                    $this->stream->write(
                        $this->codec->encodeClient(FrameType::Close, $frame->payload),
                        $this->writeTimeoutSeconds,
                    );
                    $this->closed = true;
                    $this->stream->close();
                    return null;
                }

                $message = $this->consumeDataFrame($frame->type, $frame->payload, $frame->final);
                if ($message !== null) {
                    return $message;
                }
                continue;
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return null;
            }
            $chunk = $this->stream->read(65_536, $remaining);
            if ($chunk === '') {
                return null;
            }
            $this->buffer .= $chunk;
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        try {
            $this->stream->write(
                $this->codec->encodeClient(FrameType::Close, pack('n', 1000)),
                $this->writeTimeoutSeconds,
            );
        } finally {
            $this->closed = true;
            $this->stream->close();
        }
    }

    /** @return array{string, string} */
    private static function readHandshake(ByteStream $stream, float $timeoutSeconds): array
    {
        $response = '';
        $deadline = microtime(true) + $timeoutSeconds;
        while (!str_contains($response, "\r\n\r\n")) {
            if (strlen($response) > 16_384) {
                throw new ProtocolException('WebSocket handshake headers exceed 16 KiB.');
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new ProtocolException('WebSocket handshake timed out.');
            }
            $chunk = $stream->read(4_096, $remaining);
            if ($chunk === '') {
                throw new ProtocolException('WebSocket server closed during handshake.');
            }
            $response .= $chunk;
        }

        [$headers, $remaining] = explode("\r\n\r\n", $response, 2);

        return [$headers."\r\n\r\n", $remaining];
    }

    private static function validateHandshake(string $response, string $key): void
    {
        [$headers] = explode("\r\n\r\n", $response, 2);
        $lines = explode("\r\n", $headers);
        $status = array_shift($lines);
        if (preg_match('/^HTTP\/1\.[01] 101(?: |$)/', $status) !== 1) {
            throw new ProtocolException('WebSocket server rejected the upgrade request.');
        }

        $values = [];
        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $values[strtolower(trim($name))] = trim($value);
        }

        $expected = base64_encode(sha1($key.self::ACCEPT_GUID, true));
        if (!hash_equals($expected, $values['sec-websocket-accept'] ?? '')) {
            throw new ProtocolException('WebSocket server returned an invalid accept key.');
        }
        if (strtolower($values['upgrade'] ?? '') !== 'websocket') {
            throw new ProtocolException('WebSocket upgrade response is missing the Upgrade header.');
        }
        $connectionTokens = array_map('trim', explode(',', strtolower($values['connection'] ?? '')));
        if (!in_array('upgrade', $connectionTokens, true)) {
            throw new ProtocolException('WebSocket upgrade response is missing the Connection token.');
        }
    }

    private function consumeDataFrame(FrameType $type, string $payload, bool $final): ?string
    {
        if ($type === FrameType::Text) {
            if ($this->fragmenting) {
                throw new ProtocolException('Received a new WebSocket data frame during fragmentation.');
            }
            if ($final) {
                return $this->validateUtf8($payload);
            }
            $this->fragmenting = true;
            $this->fragment = $payload;
            return null;
        }

        if ($type !== FrameType::Continuation || !$this->fragmenting) {
            throw new ProtocolException('Received an unexpected WebSocket continuation frame.');
        }
        $this->fragment .= $payload;
        if (strlen($this->fragment) > $this->maxPayloadBytes) {
            throw new ProtocolException('Fragmented WebSocket message exceeds the configured byte limit.');
        }
        if (!$final) {
            return null;
        }

        $message = $this->fragment;
        $this->fragment = '';
        $this->fragmenting = false;

        return $this->validateUtf8($message);
    }

    private function validateUtf8(string $payload): string
    {
        if (preg_match('//u', $payload) !== 1) {
            throw new ProtocolException('Received invalid UTF-8 in a WebSocket text frame.');
        }

        return $payload;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new ProtocolException('WebSocket transport is closed.');
        }
    }
}
