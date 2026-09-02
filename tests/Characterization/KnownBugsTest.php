<?php declare(strict_types=1);

namespace Hardcastle\Buffer\Test\Characterization;

use Hardcastle\Buffer\Buffer;
use PHPUnit\Framework\TestCase;
use TypeError;
use ValueError;

/**
 * Pins down six defects that exist in 1.x, so the refactor cannot change them
 * by accident and the fixes become visible as deliberate changes.
 *
 * Every test here asserts WRONG behaviour on purpose. Phase 2 of
 * docs/RELEASE-2.0-PLAN.md replaces this class with NodeParityTest, which
 * asserts what Node actually does.
 */
final class KnownBugsTest extends TestCase
{
    /**
     * Bug 1: the max(0, ...) clamp is undone by the following min(), so a start
     * past the end produces a negative length. Node returns an empty buffer.
     */
    public function testSubArrayPastTheEndCrashes(): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('must be greater than or equal to 0');

        Buffer::from('0102030405', 'hex')->subArray(10);
    }

    /**
     * Bug 2: Node buffers are fixed size and an out-of-range write is a silent
     * no-op. Here a mistyped index silently reallocates and grows the buffer.
     */
    public function testOffsetSetPastTheEndGrowsTheBuffer(): void
    {
        $buf = Buffer::from('0102030405', 'hex');
        $buf[9] = 0xFF;

        $this->assertSame(10, $buf->getLength(), 'buffer grew from 5 to 10 bytes');
        $this->assertSame('010203040500000000FF', $buf->toString('hex'), 'gap filled with zeroes, value at index 9');
    }

    /**
     * Bug 3: unset() on SplFixedArray writes null into the slot. Node has no
     * unset at all. The null only surfaces later, in an unrelated call.
     */
    public function testOffsetUnsetLeavesANullHoleThatBreaksToString(): void
    {
        $buf = Buffer::from('0102030405', 'hex');
        unset($buf[2]);

        $this->assertSame(5, $buf->getLength(), 'length still claims 5 bytes');
        $this->assertSame([1, 2, null, 4, 5], $buf->toArray(), 'but one slot is null');

        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('dechex()');
        $buf->toString('hex');
    }

    /**
     * Bug 4: the hex -> BigInteger -> decimal string -> (int) route saturates
     * at PHP_INT_MAX once the buffer exceeds 8 bytes. Deterministically wrong,
     * which makes it harder to notice than a random value would be.
     */
    public function testToIntSilentlySaturatesBeyondEightBytes(): void
    {
        $this->assertSame(PHP_INT_MAX, Buffer::from(str_repeat('FF', 32), 'hex')->toInt());
        $this->assertSame(PHP_INT_MAX, Buffer::from(str_repeat('FF', 9), 'hex')->toInt());

        // The correct value is still available through the string route.
        $this->assertSame(
            '115792089237316195423570985008687907853269984665640564039457584007913129639935',
            Buffer::from(str_repeat('FF', 32), 'hex')->toDecimalString()
        );
    }

    /**
     * Bug 5: $length is public and writable, so it can be moved out of sync
     * with the actual byte storage from outside the class.
     */
    public function testPublicLengthCanBeSetOutOfSyncWithTheStorage(): void
    {
        $buf = Buffer::from('0102030405', 'hex');
        $buf->length = 2;

        $this->assertSame(2, $buf->getLength(), 'the buffer now claims 2 bytes');
        $this->assertSame('0102', $buf->toString('hex'), 'and hides the other three');
        $this->assertSame(5, $buf->getBytesArray()->getSize(), 'while the storage still holds 5');
    }

    /**
     * Bug 6: concat() with a totalLength larger than the inputs leaves the
     * surplus slots as null instead of zero-filling them, as Node does. Same
     * failure class as bug 3: it only surfaces on the next read.
     */
    public function testConcatWithOversizedTotalLengthLeavesNullHoles(): void
    {
        $result = Buffer::concat([Buffer::from('AABB', 'hex'), Buffer::from('CC', 'hex')], 6);

        $this->assertSame(6, $result->getLength());
        $this->assertSame([170, 187, 204, null, null, null], $result->toArray());

        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('dechex()');
        $result->toString('hex');
    }
}
