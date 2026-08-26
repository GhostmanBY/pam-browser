<?php

declare(strict_types=1);

namespace Pam\Browser\Transport;

use Pam\Stream\DuplexStream;

final readonly class PamByteStream implements ByteStream
{
    public function __construct(private DuplexStream $stream)
    {
    }

    public function read(int $length, float $timeoutSeconds): string
    {
        try {
            return $this->stream->read($length, $timeoutSeconds);
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'Stream operation timed out.') {
                return '';
            }
            throw $exception;
        }
    }

    public function write(string $data, float $timeoutSeconds): int
    {
        return $this->stream->write($data, $timeoutSeconds);
    }

    public function close(): void
    {
        $this->stream->close();
    }
}
