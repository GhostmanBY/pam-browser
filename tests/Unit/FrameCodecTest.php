<?php

declare(strict_types=1);

namespace Pam\Browser\Tests\Unit;

use Pam\Browser\Exception\ProtocolException;
use Pam\Browser\WebSocket\FrameCodec;
use Pam\Browser\WebSocket\FrameType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FrameCodecTest extends TestCase
{
    public function testItMasksClientTextFrames(): void
    {
        $codec = new FrameCodec();

        $encoded = $codec->encodeClient(FrameType::Text, 'hello', "\x01\x02\x03\x04");

        self::assertSame("\x81\x85\x01\x02\x03\x04igohn", $encoded);
    }

    /** @return iterable<string, array{int}> */
    public static function payloadLengths(): iterable
    {
        yield 'short' => [125];
        yield '16-bit' => [126];
        yield '64-bit' => [65_536];
    }

    #[DataProvider('payloadLengths')]
    public function testItRoundTripsSupportedServerPayloadLengths(int $length): void
    {
        $payload = str_repeat('x', $length);
        $buffer = self::serverFrame($payload);
        $codec = new FrameCodec(maxPayloadBytes: $length);

        $frame = $codec->decodeServer($buffer);

        self::assertNotNull($frame);
        self::assertSame($payload, $frame->payload);
        self::assertSame('', $buffer);
    }

    public function testItRejectsMaskedServerFrames(): void
    {
        $buffer = "\x81\x80\x00\x00\x00\x00";

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('must not be masked');

        (new FrameCodec())->decodeServer($buffer);
    }

    private static function serverFrame(string $payload): string
    {
        $length = strlen($payload);
        $header = "\x81";
        if ($length <= 125) {
            return $header.chr($length).$payload;
        }
        if ($length <= 65_535) {
            return $header.chr(126).pack('n', $length).$payload;
        }

        return $header.chr(127).pack('N2', 0, $length).$payload;
    }
}
