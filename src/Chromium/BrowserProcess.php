<?php

declare(strict_types=1);

namespace Pam\Browser\Chromium;

use Pam\Browser\Exception\BrowserException;

final class BrowserProcess
{
    /** @var resource|null */
    private $process;

    private bool $closed = false;

    /** @param resource $process */
    private function __construct(
        $process,
        public readonly string $webSocketUrl,
        public readonly string $userDataDirectory,
        private readonly bool $ownsUserDataDirectory,
    ) {
        $this->process = $process;
    }

    public static function launch(?LaunchOptions $options = null): self
    {
        $options ??= new LaunchOptions();
        $executable = ExecutableLocator::discover($options->executable);
        $ownsDirectory = $options->userDataDirectory === null;
        $userDataDirectory = $options->userDataDirectory ?? self::temporaryDirectory();
        if (!is_dir($userDataDirectory) && !mkdir($userDataDirectory, 0700, true)) {
            throw new BrowserException('Unable to create the Chromium user data directory.');
        }
        self::removeStaleActivePort($userDataDirectory);

        $arguments = [
            $executable,
            '--remote-debugging-address=127.0.0.1',
            '--remote-debugging-port=0',
            '--user-data-dir='.$userDataDirectory,
            '--no-first-run',
            '--no-default-browser-check',
            '--disable-background-networking',
            '--disable-component-update',
            '--disable-sync',
        ];
        if ($options->headless) {
            $arguments[] = '--headless=new';
            $arguments[] = '--disable-gpu';
        }
        array_push($arguments, ...$options->arguments);
        $arguments[] = 'about:blank';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', '/dev/null', 'a'],
        ];
        $pipes = [];
        $process = proc_open($arguments, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            if ($ownsDirectory) {
                self::removeDirectory($userDataDirectory);
            }
            throw new BrowserException('Unable to launch Chrome/Chromium.');
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        try {
            $webSocketUrl = self::awaitEndpoint($process, $userDataDirectory, $options->timeoutSeconds);
        } catch (\Throwable $exception) {
            proc_terminate($process);
            proc_close($process);
            if ($ownsDirectory) {
                self::removeDirectory($userDataDirectory);
            }
            throw $exception;
        }

        return new self($process, $webSocketUrl, $userDataDirectory, $ownsDirectory);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if ($status['running']) {
                proc_terminate($this->process);
            }
            proc_close($this->process);
        }
        $this->process = null;

        if ($this->ownsUserDataDirectory) {
            self::removeDirectory($this->userDataDirectory);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /** @param resource $process */
    private static function awaitEndpoint($process, string $directory, float $timeoutSeconds): string
    {
        $activePort = $directory.DIRECTORY_SEPARATOR.'DevToolsActivePort';
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                throw new BrowserException(sprintf(
                    'Chrome/Chromium exited before exposing CDP (exit code %d).',
                    $status['exitcode'],
                ));
            }

            $contents = @file_get_contents($activePort);
            if (is_string($contents)) {
                $lines = preg_split('/\R/', trim($contents));
                $port = $lines[0] ?? null;
                $path = $lines[1] ?? null;
                if (is_string($port) && self::isValidPort($port) && is_string($path) && str_starts_with($path, '/')) {
                    return sprintf('ws://127.0.0.1:%d%s', (int) $port, $path);
                }
            }

            self::delay(0.01);
        } while (microtime(true) < $deadline);

        throw new BrowserException('Timed out waiting for the Chromium CDP endpoint.');
    }

    private static function isValidPort(string $value): bool
    {
        if (preg_match('/^[0-9]{1,5}$/D', $value) !== 1) {
            return false;
        }
        $port = (int) $value;

        return $port >= 1 && $port <= 65_535;
    }

    private static function removeStaleActivePort(string $directory): void
    {
        $activePort = $directory.DIRECTORY_SEPARATOR.'DevToolsActivePort';
        if ((is_file($activePort) || is_link($activePort)) && !unlink($activePort)) {
            throw new BrowserException('Unable to remove the stale Chromium CDP endpoint marker.');
        }
    }

    private static function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pam-browser-'.bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700)) {
            throw new BrowserException('Unable to create a temporary Chromium profile.');
        }

        return $directory;
    }

    private static function delay(float $seconds): void
    {
        if (function_exists('Pam\\Async\\delay')) {
            \Pam\Async\delay($seconds);
            return;
        }
        usleep((int) ($seconds * 1_000_000));
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || !str_starts_with(basename($directory), 'pam-browser-')) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($directory);
    }
}
