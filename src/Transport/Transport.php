<?php

declare(strict_types=1);

namespace Pam\Browser\Transport;

interface Transport
{
    public function send(string $payload): void;

    public function receive(float $timeoutSeconds): ?string;

    public function close(): void;
}
