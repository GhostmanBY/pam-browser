<?php

declare(strict_types=1);

namespace Pam\Browser\Transport;

interface StreamConnector
{
    public function connect(string $address, float $timeoutSeconds): ByteStream;
}
