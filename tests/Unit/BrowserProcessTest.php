<?php

declare(strict_types=1);

namespace Pam\Browser\Tests\Unit;

use Pam\Browser\Chromium\BrowserProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class BrowserProcessTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function portValues(): iterable
    {
        yield 'minimum' => ['1', true];
        yield 'common debugging port' => ['9222', true];
        yield 'maximum' => ['65535', true];
        yield 'zero' => ['0', false];
        yield 'overflow' => ['65536', false];
        yield 'sign' => ['+9222', false];
        yield 'whitespace' => [' 9222', false];
        yield 'non-digit' => ['922x', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('portValues')]
    public function testCdpPortValidationDoesNotDependOnCtype(string $value, bool $expected): void
    {
        $method = new ReflectionMethod(BrowserProcess::class, 'isValidPort');

        self::assertSame($expected, $method->invoke(null, $value));
    }

    public function testPersistentProfileDropsItsStaleCdpMarkerBeforeLaunch(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pam-browser-marker-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $marker = $directory.DIRECTORY_SEPARATOR.'DevToolsActivePort';
        self::assertSame(21, file_put_contents($marker, "9222\n/devtools/stale\n"));
        try {
            $method = new ReflectionMethod(BrowserProcess::class, 'removeStaleActivePort');
            $method->invoke(null, $directory);
            self::assertFileDoesNotExist($marker);
        } finally {
            if (is_file($marker) || is_link($marker)) unlink($marker);
            if (is_dir($directory)) rmdir($directory);
        }
    }
}
