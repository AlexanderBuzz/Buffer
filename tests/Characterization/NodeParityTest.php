<?php declare(strict_types=1);

namespace Hardcastle\Buffer\Test\Characterization;

use Hardcastle\Buffer\Buffer;
use Hardcastle\Buffer\Exception\BufferException;
use Hardcastle\Buffer\Exception\InvalidArgumentException;
use Hardcastle\Buffer\Exception\OutOfBoundsException;
use Hardcastle\Buffer\Exception\OverflowException;
use PHPUnit\Framework\TestCase;

/**
 * The six defects fixed in phase 2, now asserting Node's behaviour.
 *
 * Replaces KnownBugsTest, which pinned the broken behaviour so these fixes
 * would land as visible changes rather than silent ones.
 */
final class NodeParityTest extends TestCase
{
    /**
     * Bug 1: a start past the end used to produce a negative length and crash
     * inside SplFixedArray. Node returns an empty buffer.
     */
    public function testSubArrayPastTheEndReturnsAnEmptyBuffer(): void
    {
        $buf = Buffer::from('0102030405', 'hex');

        $this->assertSame(0, $buf->subArray(10)->getLength());
        $this->assertSame('', $buf->subArray(10)->toString('hex'));
        $this->assertSame('', $buf->subArray(10, 20)->toString('hex'));
        $this->assertSame('', $buf->subArray(5)->toString('hex'), 'start exactly at the end');
    }

    public function testSubArrayStillClampsAnEndPastTheBuffer(): void
    {
        $buf = Buffer::from('0102030405', 'hex');

        $this->assertSame('0405', $buf->subArray(3, 99)->toString('hex'));
        $this->assertSame('0102030405', $buf->subArray(0, 99)->toString('hex'));
    }

    /**
     * Bug 2: buffers are fixed size in Node, and a write outside them is a
     * silent no-op. It used to reallocate and grow the buffer instead.
     */
    public function testOffsetSetPastTheEndIsANoOp(): void
    {
        $buf = Buffer::from('0102030405', 'hex');
        $buf[9] = 0xFF;

        $this->assertSame(5, $buf->getLength(), 'length is unchanged');
        $this->assertSame('0102030405', $buf->toString('hex'), 'content is unchanged');
    }

    public function testOffsetSetWithinBoundsStillWorks(): void
    {
        $buf = Buffer::from('0102030405', 'hex');
        $buf[0] = 0xFF;
        $buf[4] = 0xEE;

        $this->assertSame('FF020304EE', $buf->toString('hex'));
    }

    /**
     * Bug 3: unset() used to write null into the storage, which only surfaced
     * on a later read. Node has no unset, so this is a no-op.
     */
    public function testOffsetUnsetIsANoOp(): void
    {
        $buf = Buffer::from('0102030405', 'hex');
        unset($buf[2]);

        $this->assertSame(5, $buf->getLength());
        $this->assertSame('0102030405', $buf->toString('hex'));
        $this->assertSame([1, 2, 3, 4, 5], $buf->toArray(), 'no null holes');
    }

    /**
     * Bug 4: toInt() used to saturate at PHP_INT_MAX past 8 bytes. It now
     * refuses instead of silently returning a wrong number.
     */
    public function testToIntRejectsBuffersWiderThanEightBytes(): void
    {
        $this->expectException(OverflowException::class);
        $this->expectExceptionMessage('Cannot convert a 32 byte buffer to int without loss');

        Buffer::from(str_repeat('FF', 32), 'hex')->toInt();
    }

    public function testToIntAcceptsUpToEightBytes(): void
    {
        $this->assertSame(0, Buffer::alloc(0)->toInt());
        $this->assertSame(255, Buffer::from('FF', 'hex')->toInt());
        $this->assertSame(72623859790382856, Buffer::from('0102030405060708', 'hex')->toInt());
        $this->assertSame(PHP_INT_MAX, Buffer::from('7FFFFFFFFFFFFFFF', 'hex')->toInt());
    }

    public function testToDecimalStringRemainsTheRouteForWideValues(): void
    {
        $this->assertSame(
            '115792089237316195423570985008687907853269984665640564039457584007913129639935',
            Buffer::from(str_repeat('FF', 32), 'hex')->toDecimalString()
        );
    }

    /**
     * Bug 6: concat() with a totalLength larger than its inputs used to leave
     * null holes. Node zero-fills the surplus.
     */
    public function testConcatZeroFillsSurplusLength(): void
    {
        $result = Buffer::concat([Buffer::from('AABB', 'hex'), Buffer::from('CC', 'hex')], 6);

        $this->assertSame(6, $result->getLength());
        $this->assertSame('AABBCC000000', $result->toString('hex'));
        $this->assertSame([170, 187, 204, 0, 0, 0], $result->toArray());
    }

    public function testConcatStillTruncatesWhenTotalLengthIsSmaller(): void
    {
        $parts = [Buffer::from('AABB', 'hex'), Buffer::from('CCDD', 'hex')];

        $this->assertSame('AABB', Buffer::concat($parts, 2)->toString('hex'));
        $this->assertSame('AABBCCDD', Buffer::concat($parts, 4)->toString('hex'));
    }

    /**
     * The uniform exception strategy: every accessor reports an out-of-range
     * offset the same way, rather than letting SplFixedArray throw its own.
     */
    public function testEveryAccessorThrowsTheSameOutOfBoundsException(): void
    {
        $buf = Buffer::from('0102', 'hex');

        $cases = [
            static fn (): int => $buf->readUInt8(50),
            static fn (): int => $buf->readInt8(-1),
            static fn (): int => $buf->readUInt16BE(1),
            static fn (): int => $buf->readUInt32BE(0),
            static fn (): float => $buf->readDoubleBE(0),
            static fn (): float => $buf->readFloatBE(0),
            static fn (): int => $buf->writeUInt8(0, 50),
            static fn (): int => $buf->writeUInt32BE(0, 0),
            static fn (): int => $buf->writeDoubleLE(0.0, 0),
            static fn (): int => $buf[50],
        ];

        foreach ($cases as $index => $case) {
            try {
                $case();
                $this->fail("case $index should have thrown");
            } catch (OutOfBoundsException $e) {
                $this->assertStringContainsString('out of range', $e->getMessage());
            }
        }
    }

    public function testOutOfBoundsExceptionNamesTheOperationAndTheSizes(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('readUInt32BE at offset 0 needs 4 byte(s), but the buffer is 2 byte(s) long.');

        Buffer::from('0102', 'hex')->readUInt32BE(0);
    }

    public function testAccessorsStillWorkExactlyAtTheBoundary(): void
    {
        $buf = Buffer::from('01020304', 'hex');

        $this->assertSame(4, $buf->readUInt8(3), 'last valid byte');
        $this->assertSame(0x0304, $buf->readUInt16BE(2), 'last valid 16-bit read');
        $this->assertSame(0x01020304, $buf->readUInt32BE(0), 'exact fit');
    }

    public function testExceptionsShareACommonBaseAndStayCatchableAsException(): void
    {
        foreach ([
            static fn (): int => Buffer::from('0102', 'hex')->readUInt32BE(0),
            static fn (): int => Buffer::from(str_repeat('FF', 9), 'hex')->toInt(),
            static fn (): Buffer => Buffer::from(3.14),
        ] as $case) {
            try {
                $case();
                $this->fail('should have thrown');
            } catch (BufferException $e) {
                $this->assertInstanceOf(\Exception::class, $e, 'must stay catchable as \Exception');
            }
        }
    }

    public function testUnsupportedSourceThrowsInvalidArgument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Buffer does not support source type: double');

        Buffer::from(3.14);
    }
}
