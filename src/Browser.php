<?php

declare(strict_types=1);

namespace Pam\Browser;

use Pam\Browser\Chromium\BrowserProcess;
use Pam\Browser\Chromium\LaunchOptions;
use Pam\Browser\Transport\StreamConnector;
use Pam\Browser\Transport\WebSocketTransport;

final class Browser
{
    private bool $closed = false;

    private function __construct(
        private readonly BrowserProcess $process,
        private readonly Connection $connection,
    ) {
    }

    public static function launch(
        ?LaunchOptions $options = null,
        ?StreamConnector $connector = null,
    ): self {
        $options ??= new LaunchOptions();
        $process = BrowserProcess::launch($options);
        try {
            $transport = WebSocketTransport::connect(
                $process->webSocketUrl,
                $connector,
                $options->timeoutSeconds,
            );
        } catch (\Throwable $exception) {
            $process->close();
            throw $exception;
        }

        return new self($process, new Connection($transport));
    }

    /** @return array<string, mixed> */
    public function version(): array
    {
        return $this->connection->command('Browser.getVersion');
    }

    public function createTarget(string $url = 'about:blank'): string
    {
        $result = $this->connection->command('Target.createTarget', ['url' => $url]);
        $targetId = $result['targetId'] ?? null;
        if (!is_string($targetId) || $targetId === '') {
            throw new Exception\ProtocolException('CDP did not return a target id.');
        }

        return $targetId;
    }

    public function profileDirectory(): string
    {
        return $this->process->userDataDirectory;
    }

    public function newPage(string $url = 'about:blank', float $timeoutSeconds = 30.0): Page
    {
        $targetId = $this->createTarget();
        $attached = $this->connection->command('Target.attachToTarget', [
            'targetId' => $targetId,
            'flatten' => true,
        ]);
        $sessionId = $attached['sessionId'] ?? null;
        if (!is_string($sessionId) || $sessionId === '') {
            throw new Exception\ProtocolException('CDP did not return a session id.');
        }

        $page = new Page($this->connection, $targetId, $sessionId);
        $page->enable();
        if ($url !== 'about:blank') {
            $page->navigate($url, $timeoutSeconds);
        }

        return $page;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        try {
            $this->connection->command('Browser.close', timeoutSeconds: 2.0);
        } catch (\Throwable) {
            // Process ownership below guarantees cleanup if CDP is already gone.
        } finally {
            $this->connection->close();
            $this->process->close();
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
