<?php declare(strict_types=1);

namespace Hardcastle\Buffer\Test\Characterization;

use Brick\Math\BigInteger;
use Hardcastle\Buffer\Buffer;
use Hardcastle\Buffer\Exception\InvalidArgumentException;
use Hardcastle\Buffer\Exception\OutOfBoundsException;
use PHPUnit\Framework\TestCase;

/**
 * Methods added in 2.0 to close gaps against Node's Buffer.
 */
final class NodeAdditionsTest extends TestCase
{
    public function testReadUIntBE(): void
    {
        $buf = Buffer::from('0102030405060708', 'hex');

        $this->assertSame(0x01, $buf->readUIntBE(0, 1));
        $this->assertSame(0x0102, $buf->readUIntBE(0, 2));
        $this->assertSame(0x010203, $buf->readUIntBE(0, 3));
        $this->assertSame(0x01020304, $buf->readUIntBE(0, 4));
        $this->assertSame(0x0102030405, $buf->readUIntBE(0, 5));
        $this->assertSame(0x010203040506, $buf->readUIntBE(0, 6));
    }

    public function testReadUIntLE(): void
    {
        $buf = Buffer::from('0102030405060708', 'hex');

        $this->assertSame(0x01, $buf->readUIntLE(0, 1));
        $this->assertSame(0x0201, $buf->readUIntLE(0, 2));
        $this->assertSame(0x030201, $buf->readUIntLE(0, 3));
        $this->assertSame(0x060504030201, $buf->readUIntLE(0, 6));
    }

    public function testReadUIntAtOffset(): void
    {
        $buf = Buffer::from('FFFF0102030405', 'hex');

        $this->assertSame(0x010203, $buf->readUIntBE(2, 3));
        $this->assertSame(0x030201, $buf->readUIntLE(2, 3));
    }

    public function testReadIntSignExtends(): void
    {
        $this->assertSame(-1, Buffer::from('FF', 'hex')->readIntBE(0, 1));
        $this->assertSame(-1, Buffer::from('FFFFFF', 'hex')->readIntBE(0, 3));
        $this->assertSame(-2, Buffer::from('FFFFFE', 'hex')->readIntBE(0, 3));
        $this->assertSame(-8388608, Buffer::from('800000', 'hex')->readIntBE(0, 3));
        $this->assertSame(8388607, Buffer::from('7FFFFF', 'hex')->readIntBE(0, 3));
    }

    public function testReadIntLESignExtends(): void
    {
        $this->assertSame(-1, Buffer::from('FFFFFF', 'hex')->readIntLE(0, 3));
        $this->assertSame(-2, Buffer::from('FEFFFF', 'hex')->readIntLE(0, 3));
    }

    /**
     * @dataProvider variableWidthProvider
     */
    public function testVariableWidthRoundTrip(int $byteLength, int $value): void
    {
        $be = Buffer::alloc($byteLength);
        $be->writeUIntBE($value, 0, $byteLength);
        $this->assertSame($value, $be->readUIntBE(0, $byteLength), "BE width $byteLength");

        $le = Buffer::alloc($byteLength);
        $le->writeUIntLE($value, 0, $byteLength);
        $this->assertSame($value, $le->readUIntLE(0, $byteLength), "LE width $byteLength");
    }

    public static function variableWidthProvider(): array
    {
        $cases = [];
        for ($width = 1; $width <= 6; $width++) {
            $max = (1 << ($width * 8)) - 1;
            foreach ([0, 1, intdiv($max, 2), $max] as $value) {
                $cases["width $width value $value"] = [$width, $value];
            }
        }

        return $cases;
    }

    public function testWriteUIntBEProducesBigEndianLayout(): void
    {
        $buf = Buffer::alloc(3);
        $this->assertSame(3, $buf->writeUIntBE(0x010203, 0, 3));
        $this->assertSame('010203', $buf->toString('hex'));
    }

    public function testWriteUIntLEProducesLittleEndianLayout(): void
    {
        $buf = Buffer::alloc(3);
        $this->assertSame(3, $buf->writeUIntLE(0x010203, 0, 3));
        $this->assertSame('030201', $buf->toString('hex'));
    }

    public function testSignedWritesShareTheUnsignedByteLayout(): void
    {
        $signed = Buffer::alloc(3);
        $signed->writeIntBE(-2, 0, 3);

        $this->assertSame('FFFFFE', $signed->toString('hex'));
        $this->assertSame(-2, $signed->readIntBE(0, 3));
    }

    /**
     * @dataProvider badByteLengthProvider
     */
    public function testByteLengthOutsideOneToSixIsRejected(int $byteLength): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be between 1 and 6');

        Buffer::alloc(16)->readUIntBE(0, $byteLength);
    }

    public static function badByteLengthProvider(): array
    {
        return [[0], [-1], [7], [8]];
    }

    public function testVariableWidthReadRespectsBounds(): void
    {
        $this->expectException(OutOfBoundsException::class);
        Buffer::from('0102', 'hex')->readUIntBE(0, 4);
    }

    public function testReadBigUInt64BE(): void
    {
        $this->assertTrue(
            BigInteger::of('72623859790382856')->isEqualTo(Buffer::from('0102030405060708', 'hex')->readBigUInt64BE())
        );
        $this->assertTrue(
            BigInteger::of('18446744073709551615')->isEqualTo(Buffer::from('FFFFFFFFFFFFFFFF', 'hex')->readBigUInt64BE()),
            'the full unsigned 64-bit range, which no PHP int can hold'
        );
    }

    public function testReadBigUInt64LE(): void
    {
        $this->assertTrue(
            BigInteger::of('578437695752307201')->isEqualTo(Buffer::from('0102030405060708', 'hex')->readBigUInt64LE())
        );
    }

    public function testReadBigInt64SignExtends(): void
    {
        $this->assertTrue(BigInteger::of(-1)->isEqualTo(Buffer::from('FFFFFFFFFFFFFFFF', 'hex')->readBigInt64BE()));
        $this->assertTrue(
            BigInteger::of('-9223372036854775808')->isEqualTo(Buffer::from('8000000000000000', 'hex')->readBigInt64BE())
        );
        $this->assertTrue(
            BigInteger::of('9223372036854775807')->isEqualTo(Buffer::from('7FFFFFFFFFFFFFFF', 'hex')->readBigInt64BE())
        );
    }

    /**
     * @dataProvider bigValueProvider
     */
    public function testBigUInt64RoundTrip(string $value): void
    {
        $be = Buffer::alloc(8);
        $be->writeBigUInt64BE(BigInteger::of($value));
        $this->assertTrue(BigInteger::of($value)->isEqualTo($be->readBigUInt64BE()), 'BE');

        $le = Buffer::alloc(8);
        $le->writeBigUInt64LE(BigInteger::of($value));
        $this->assertTrue(BigInteger::of($value)->isEqualTo($le->readBigUInt64LE()), 'LE');
    }

    public static function bigValueProvider(): array
    {
        return [
            'zero' => ['0'],
            'one' => ['1'],
            'a drop amount' => ['100000000'],
            'int64 max' => ['9223372036854775807'],
            'past int64 max' => ['9223372036854775808'],
            'uint64 max' => ['18446744073709551615'],
        ];
    }

    /**
     * @dataProvider signedBigValueProvider
     */
    public function testBigInt64RoundTrip(string $value): void
    {
        $be = Buffer::alloc(8);
        $be->writeBigInt64BE(BigInteger::of($value));
        $this->assertTrue(BigInteger::of($value)->isEqualTo($be->readBigInt64BE()), 'BE');

        $le = Buffer::alloc(8);
        $le->writeBigInt64LE(BigInteger::of($value));
        $this->assertTrue(BigInteger::of($value)->isEqualTo($le->readBigInt64LE()), 'LE');
    }

    public static function signedBigValueProvider(): array
    {
        return [
            'int64 min' => ['-9223372036854775808'],
            'minus one' => ['-1'],
            'zero' => ['0'],
            'one' => ['1'],
            'int64 max' => ['9223372036854775807'],
        ];
    }

    public function testBigWritesAcceptIntAndString(): void
    {
        $fromInt = Buffer::alloc(8);
        $fromInt->writeBigUInt64BE(255);

        $fromString = Buffer::alloc(8);
        $fromString->writeBigUInt64BE('255');

        $this->assertSame('00000000000000FF', $fromInt->toString('hex'));
        $this->assertSame($fromInt->toString('hex'), $fromString->toString('hex'));
    }

    public function testBigEndiannessIsMirrored(): void
    {
        $be = Buffer::alloc(8);
        $le = Buffer::alloc(8);
        $be->writeBigUInt64BE(BigInteger::of('72623859790382856'));
        $le->writeBigUInt64LE(BigInteger::of('72623859790382856'));

        $this->assertSame('0102030405060708', $be->toString('hex'));
        $this->assertSame('0807060504030201', $le->toString('hex'));
    }

    public function testBigReadsRespectBounds(): void
    {
        $this->expectException(OutOfBoundsException::class);
        Buffer::from('01020304', 'hex')->readBigUInt64BE();
    }

    public function testBigUInt64ReplacesTheToIntDetourForWideValues(): void
    {
        $buf = Buffer::from('FFFFFFFFFFFFFFFF', 'hex');

        // toInt() saturated here in 1.x; the BigInteger route is exact.
        $this->assertSame('18446744073709551615', (string)$buf->readBigUInt64BE());
        $this->assertSame('18446744073709551615', $buf->toDecimalString());
    }

    public function testToJSON(): void
    {
        $this->assertSame(
            ['type' => 'Buffer', 'data' => [1, 2, 255]],
            Buffer::from('0102FF', 'hex')->toJSON()
        );
        $this->assertSame(['type' => 'Buffer', 'data' => []], Buffer::alloc(0)->toJSON());
    }

    public function testToJSONMatchesNodeSerialisation(): void
    {
        $this->assertSame(
            '{"type":"Buffer","data":[1,2,255]}',
            json_encode(Buffer::from('0102FF', 'hex')->toJSON())
        );
    }

    public function testKeys(): void
    {
        $this->assertSame([0, 1, 2], iterator_to_array(Buffer::from('010203', 'hex')->keys()));
        $this->assertSame([], iterator_to_array(Buffer::alloc(0)->keys()));
    }

    public function testValues(): void
    {
        $this->assertSame([1, 2, 255], iterator_to_array(Buffer::from('0102FF', 'hex')->values()));
        $this->assertSame([], iterator_to_array(Buffer::alloc(0)->values()));
    }

    public function testEntries(): void
    {
        $this->assertSame(
            [[0, 1], [1, 2], [2, 255]],
            iterator_to_array(Buffer::from('0102FF', 'hex')->entries())
        );
    }

    public function testIteratorsAreUsableInForeach(): void
    {
        $collected = [];
        foreach (Buffer::from('0A0B', 'hex')->entries() as [$index, $byte]) {
            $collected[$index] = $byte;
        }

        $this->assertSame([0 => 10, 1 => 11], $collected);
    }

    public function testTranscode(): void
    {
        if (!function_exists('mb_convert_encoding')) {
            $this->markTestSkipped('ext-mbstring is not available');
        }

        $utf8 = Buffer::from('äö');
        $latin1 = Buffer::transcode($utf8, 'UTF-8', 'ISO-8859-1');

        $this->assertSame('E4F6', $latin1->toString('hex'));
        $this->assertSame($utf8->toString('hex'), Buffer::transcode($latin1, 'ISO-8859-1', 'UTF-8')->toString('hex'));
    }
}
