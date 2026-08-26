<?php

declare(strict_types=1);

namespace Pam\Stream;

interface DuplexStream
{
    public function read(?int $length = null, ?float $timeout = null): string;

    public function write(string $data, ?float $timeout = null): int;

    public function close(): void;
}

final class Streams
{
    public static function connect(
        string $address,
        float $timeout = 10.0,
        bool $tls = false,
        int $highWaterMark = 65_536,
    ): DuplexStream;
}
