<?php

declare(strict_types=1);

namespace Pam\Browser\Chromium;

use Pam\Browser\Exception\BrowserException;

final readonly class LaunchOptions
{
    /** @param list<string> $arguments */
    public function __construct(
        public ?string $executable = null,
        public ?string $userDataDirectory = null,
        public bool $headless = true,
        public float $timeoutSeconds = 15.0,
        public array $arguments = [],
    ) {
        if ($timeoutSeconds <= 0.0 || $timeoutSeconds > 300.0) {
            throw new BrowserException('Chromium launch timeout must be between 0 and 300 seconds.');
        }
        foreach ($arguments as $argument) {
            if ($argument === '' || str_contains($argument, "\0")) {
                throw new BrowserException('Chromium arguments must be non-empty and contain no NUL bytes.');
            }
        }
    }
}
