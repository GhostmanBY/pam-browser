<?php

declare(strict_types=1);

namespace Pam\Browser\Chromium;

use Pam\Browser\Exception\BrowserException;

final class ExecutableLocator
{
    /** @var list<string> */
    private const NAMES = [
        'google-chrome-stable',
        'google-chrome',
        'chromium',
        'chromium-browser',
        'chrome',
    ];

    public static function discover(?string $preferred = null): string
    {
        if ($preferred !== null) {
            return self::assertExecutable($preferred);
        }

        $configured = getenv('PAM_CHROME_PATH');
        if (is_string($configured) && $configured !== '') {
            return self::assertExecutable($configured);
        }

        $path = getenv('PATH');
        foreach (is_string($path) ? explode(PATH_SEPARATOR, $path) : [] as $directory) {
            foreach (self::NAMES as $name) {
                $candidate = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;
                if (is_file($candidate) && is_executable($candidate)) {
                    $resolved = realpath($candidate);

                    return is_string($resolved) ? $resolved : $candidate;
                }
            }
        }

        throw new BrowserException(
            'Chrome/Chromium was not found. Set PAM_CHROME_PATH to an executable path.',
        );
    }

    private static function assertExecutable(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || !is_file($path) || !is_executable($path)) {
            throw new BrowserException(sprintf('Chromium executable is not usable: %s', $path));
        }

        $resolved = realpath($path);

        return is_string($resolved) ? $resolved : $path;
    }
}
