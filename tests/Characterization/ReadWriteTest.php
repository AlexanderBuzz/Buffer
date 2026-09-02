<?php declare(strict_types=1);

namespace Hardcastle\Buffer\Test\Characterization;

use Hardcastle\Buffer\Buffer;
use PHPUnit\Framework\TestCase;

/**
 * All fixed-width read and write accessors: exact byte layout, sign handling at
 * the boundaries, and read/write round-trips.
 */
final class ReadWriteTest extends TestCase
{
    public function testReadInt8SignBoundary(): void
    {
        $buf = Buffer::from('007F80FF', 'hex');

        $this->assertSame(0, $buf->readInt8(0));
        $this->assertSame(127, $buf->readInt8(1));
        $this->assertSame(-128, $buf->readInt8(2));
        $this->assertSame(-1, $buf->readInt8(3));
    }

    public function testReadUInt8(): void
    {
        $buf = Buffer::from('007F80FF', 'hex');

        $this->assertSame(0, $buf->readUInt8(0));
        $this->assertSame(127, $buf->readUInt8(1));
        $this->assertSame(128, $buf->readUInt8(2));
        $this->assertSame(255, $buf->readUInt8(3));
    }

    public function testRead16BitEndianness(): void
    {
        $buf = Buffer::from('0102', 'hex');

        $this->assertSame(0x0102, $buf->readUInt16BE(0));
        $this->assertSame(0x0201, $buf->readUInt16LE(0));
        $this->assertSame(0x0102, $buf->readInt16BE(0));
        $this->assertSame(0x0201, $buf->readInt16LE(0));
    }

    public function testRead16BitSignBoundary(): void
    {
        $this->assertSame(32767, Buffer::from('7FFF', 'hex')->readInt16BE(0));
        $this->assertSame(-32768, Buffer::from('8000', 'hex')->readInt16BE(0));
        $this->assertSame(-1, Buffer::from('FFFF', 'hex')->readInt16BE(0));
        $this->assertSame(65535, Buffer::from('FFFF', 'hex')->readUInt16BE(0));
    }

    public function testRead32BitEndianness(): void
    {
        $buf = Buffer::from('01020304', 'hex');

        $this->assertSame(0x01020304, $buf->readUInt32BE(0));
        $this->assertSame(0x04030201, $buf->readUInt32LE(0));
        $this->assertSame(0x01020304, $buf->readInt32BE(0));
        $this->assertSame(0x04030201, $buf->readInt32LE(0));
    }

    public function testRead32BitSignBoundary(): void
    {
        $this->assertSame(2147483647, Buffer::from('7FFFFFFF', 'hex')->readInt32BE(0));
        $this->assertSame(-2147483648, Buffer::from('80000000', 'hex')->readInt32BE(0));
        $this->assertSame(-1, Buffer::from('FFFFFFFF', 'hex')->readInt32BE(0));
        $this->assertSame(4294967295, Buffer::from('FFFFFFFF', 'hex')->readUInt32BE(0));
    }

    public function testReadAtNonZeroOffset(): void
    {
        $buf = Buffer::from('FFFF01020304', 'hex');

        $this->assertSame(0x0102, $buf->readUInt16BE(2));
        $this->assertSame(0x01020304, $buf->readUInt32BE(2));
    }

    /**
     * @dataProvider writeProvider
     */
    public function testWriteProducesExactByteLayout(string $method, int $value, string $expectedHex): void
    {
        $buf = Buffer::alloc(8);
        $returned = $buf->$method($value, 0);

        $this->assertSame($expectedHex, $buf->toString('hex', 0, intdiv(strlen($expectedHex), 2)), $method);
        $this->assertSame(intdiv(strlen($expectedHex), 2), $returned, "$method must return the offset past the write");
    }

    public static function writeProvider(): array
    {
        return [
            ['writeUInt8', 0xAB, 'AB'],
            ['writeInt8', -1, 'FF'],
            ['writeUInt16BE', 0x0102, '0102'],
            ['writeUInt16LE', 0x0102, '0201'],
            ['writeInt16BE', -2, 'FFFE'],
            ['writeInt16LE', -2, 'FEFF'],
            ['writeUInt32BE', 0x01020304, '01020304'],
            ['writeUInt32LE', 0x01020304, '04030201'],
            ['writeInt32BE', -2, 'FFFFFFFE'],
            ['writeInt32LE', -2, 'FEFFFFFF'],
        ];
    }

    public function testWriteMasksOverlongValues(): void
    {
        $buf = Buffer::alloc(1);
        $buf->writeUInt8(0x1FF, 0);
        $this->assertSame('FF', $buf->toString('hex'), 'value is masked to one byte');
    }

    public function testWriteAtOffsetLeavesNeighboursUntouched(): void
    {
        $buf = Buffer::alloc(6, 0xAA);
        $buf->writeUInt16BE(0x0102, 2);

        $this->assertSame('AAAA0102AAAA', $buf->toString('hex'));
    }

    /**
     * @dataProvider roundTripProvider
     */
    public function testIntegerRoundTrip(string $write, string $read, int $value, int $size): void
    {
        $buf = Buffer::alloc($size);
        $buf->$write($value, 0);

        $this->assertSame($value, $buf->$read(0), "$write/$read round trip for $value");
    }

    public static function roundTripProvider(): array
    {
        $cases = [];
        foreach ([
            ['writeUInt8', 'readUInt8', [0, 1, 127, 128, 255], 1],
            ['writeInt8', 'readInt8', [-128, -1, 0, 1, 127], 1],
            ['writeUInt16BE', 'readUInt16BE', [0, 1, 32767, 32768, 65535], 2],
            ['writeUInt16LE', 'readUInt16LE', [0, 1, 32767, 32768, 65535], 2],
            ['writeInt16BE', 'readInt16BE', [-32768, -1, 0, 32767], 2],
            ['writeInt16LE', 'readInt16LE', [-32768, -1, 0, 32767], 2],
            ['writeUInt32BE', 'readUInt32BE', [0, 1, 2147483647, 4294967295], 4],
            ['writeUInt32LE', 'readUInt32LE', [0, 1, 2147483647, 4294967295], 4],
            ['writeInt32BE', 'readInt32BE', [-2147483648, -1, 0, 2147483647], 4],
            ['writeInt32LE', 'readInt32LE', [-2147483648, -1, 0, 2147483647], 4],
        ] as [$write, $read, $values, $size]) {
            foreach ($values as $value) {
                $cases["$write($value)"] = [$write, $read, $value, $size];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider floatProvider
     */
    public function testFloatRoundTrip(string $write, string $read, float $value, int $size): void
    {
        $buf = Buffer::alloc($size);
        $buf->$write($value, 0);

        $this->assertEqualsWithDelta($value, $buf->$read(0), 1e-6, "$write/$read round trip");
    }

    public static function floatProvider(): array
    {
        $cases = [];
        foreach ([0.0, 1.0, -1.0, 0.5, 3.5, -12345.75] as $value) {
            $cases["floatBE($value)"] = ['writeFloatBE', 'readFloatBE', $value, 4];
            $cases["floatLE($value)"] = ['writeFloatLE', 'readFloatLE', $value, 4];
            $cases["doubleBE($value)"] = ['writeDoubleBE', 'readDoubleBE', $value, 8];
            $cases["doubleLE($value)"] = ['writeDoubleLE', 'readDoubleLE', $value, 8];
        }

        return $cases;
    }

    public function testFloatEndiannessIsMirrored(): void
    {
        $be = Buffer::alloc(4);
        $le = Buffer::alloc(4);
        $be->writeFloatBE(1.5, 0);
        $le->writeFloatLE(1.5, 0);

        $this->assertSame($be->toString('hex'), strtoupper(bin2hex(strrev((string)hex2bin($le->toString('hex'))))));
    }

    public function testDoubleRoundTripPreservesFullPrecision(): void
    {
        $buf = Buffer::alloc(8);
        $buf->writeDoubleBE(M_PI, 0);

        $this->assertSame(M_PI, $buf->readDoubleBE(0));
    }

    public function testWriteReturnsOffsetSoWritesCanBeChained(): void
    {
        $buf = Buffer::alloc(7);
        $offset = 0;
        $offset = $buf->writeUInt8(0x01, $offset);
        $offset = $buf->writeUInt16BE(0x0203, $offset);
        $offset = $buf->writeUInt32BE(0x04050607, $offset);

        $this->assertSame(7, $offset);
        $this->assertSame('01020304050607', $buf->toString('hex'));
    }
}
