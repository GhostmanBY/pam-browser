<?php

declare(strict_types=1);

namespace Pam\Browser\Transport;

interface ByteStream
{
    public function read(int $length, float $timeoutSeconds): string;

    public function write(string $data, float $timeoutSeconds): int;

    public function close(): void;
}
