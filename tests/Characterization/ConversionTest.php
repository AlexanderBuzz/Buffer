<?php declare(strict_types=1);

namespace Hardcastle\Buffer\Test\Characterization;

use Hardcastle\Buffer\Buffer;
use PHPUnit\Framework\TestCase;

/**
 * Locks down every way a Buffer turns back into something else, plus the
 * round-trips through each supported encoding.
 */
final class ConversionTest extends TestCase
{
    public function testToStringDefaultsToHexNotUtf8(): void
    {
        // Deliberate deviation from Node, which defaults to 'utf8'. See README.
        $this->assertSame('68656C6C6F', Buffer::from('hello')->toString());
    }

    public function testToStringEncodings(): void
    {
        $buf = Buffer::from('hello world');

        $this->assertSame('68656C6C6F20776F726C64', $buf->toString('hex'));
        $this->assertSame('hello world', $buf->toString('utf8'));
        $this->assertSame('hello world', $buf->toString('utf-8'));
        $this->assertSame('aGVsbG8gd29ybGQ=', $buf->toString('base64'));
    }

    public function testToStringWithUnknownEncodingFallsBackToRawBytes(): void
    {
        $this->assertSame('hello', Buffer::from('hello')->toString('latin1'));
        $this->assertSame('hello', Buffer::from('hello')->toString('nonsense'));
    }

    public function testToStringWithRange(): void
    {
        $buf = Buffer::from('000102030405060708090A0B0C0D0E0F', 'hex');

        $this->assertSame('000102030405060708090A0B0C0D0E0F', $buf->toString('hex'));
        $this->assertSame('00010203', $buf->toString('hex', 0, 4));
        $this->assertSame('02030405', $buf->toString('hex', 2, 6));
        $this->assertSame('', $buf->toString('hex', 4, 4));
        $this->assertSame('0E0F', $buf->toString('hex', 14));
    }

    public function testToStringOnEmptyBuffer(): void
    {
        $empty = Buffer::alloc(0);

        $this->assertSame('', $empty->toString('hex'));
        $this->assertSame('', $empty->toString('utf8'));
        $this->assertSame('', $empty->toString('base64'));
    }

    public function testToArray(): void
    {
        $this->assertSame([0, 1, 254, 255], Buffer::from('0001FEFF', 'hex')->toArray());
        $this->assertSame([], Buffer::alloc(0)->toArray());
    }

    public function testToUtf8ReturnsRawBytesWithoutDecoding(): void
    {
        // Deliberate deviation: this is effectively toBinaryString(). Node would
        // replace invalid sequences with U+FFFD. See README.
        $raw = Buffer::from('80FF', 'hex')->toUtf8();

        $this->assertSame(2, strlen($raw));
        $this->assertSame("\x80\xFF", $raw);
    }

    public function testToInt(): void
    {
        $this->assertSame(0, Buffer::from('00', 'hex')->toInt());
        $this->assertSame(255, Buffer::from('FF', 'hex')->toInt());
        $this->assertSame(65535, Buffer::from('FFFF', 'hex')->toInt());
        $this->assertSame(16909060, Buffer::from('01020304', 'hex')->toInt());
    }

    public function testToDecimalStringHandlesValuesBeyondIntRange(): void
    {
        $this->assertSame('255', Buffer::from('FF', 'hex')->toDecimalString());
        $this->assertSame(
            '115792089237316195423570985008687907853269984665640564039457584007913129639935',
            Buffer::from(str_repeat('FF', 32), 'hex')->toDecimalString()
        );
    }

    public function testDebug(): void
    {
        $this->assertSame('Buffer(length=2, data=[171, 205])', Buffer::from('ABCD', 'hex')->debug());
        $this->assertSame('Buffer(length=0, data=[])', Buffer::alloc(0)->debug());
    }

    /**
     * @dataProvider roundTripProvider
     */
    public function testHexRoundTrip(string $payload): void
    {
        $buf = Buffer::from($payload);
        $this->assertSame($payload, Buffer::from($buf->toString('hex'), 'hex')->toUtf8());
    }

    /**
     * @dataProvider roundTripProvider
     */
    public function testBase64RoundTrip(string $payload): void
    {
        $buf = Buffer::from($payload);
        $this->assertSame($payload, Buffer::from($buf->toString('base64'), 'base64')->toUtf8());
    }

    /**
     * @dataProvider roundTripProvider
     */
    public function testUtf8RoundTrip(string $payload): void
    {
        $this->assertSame($payload, Buffer::from(Buffer::from($payload)->toUtf8())->toUtf8());
    }

    /**
     * @dataProvider roundTripProvider
     */
    public function testArrayRoundTrip(string $payload): void
    {
        $buf = Buffer::from($payload);
        $this->assertSame($payload, Buffer::from($buf->toArray())->toUtf8());
    }

    public static function roundTripProvider(): array
    {
        return [
            'empty' => [''],
            'ascii' => ['hello world'],
            'single null byte' => ["\x00"],
            'all byte values' => [implode('', array_map('chr', range(0, 255)))],
            'high bytes' => ["\x80\xFF\xFE"],
            'utf8 multibyte' => ['Grüße, 世界'],
            'long' => [str_repeat('abc', 500)],
        ];
    }
}
