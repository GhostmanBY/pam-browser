<?php

declare(strict_types=1);

namespace Pam\Browser\Transport;

use Pam\Stream\Streams;

final readonly class PamStreamConnector implements StreamConnector
{
    public function __construct(private int $highWaterMark = 65_536)
    {
    }

    public function connect(string $address, float $timeoutSeconds): ByteStream
    {
        return new PamByteStream(Streams::connect(
            address: $address,
            timeout: $timeoutSeconds,
            tls: false,
            highWaterMark: $this->highWaterMark,
        ));
    }
}
