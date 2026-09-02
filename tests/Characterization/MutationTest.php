<?php declare(strict_types=1);

namespace Hardcastle\Buffer\Test\Characterization;

use Exception;
use Hardcastle\Buffer\Buffer;
use PHPUnit\Framework\TestCase;

/**
 * Everything that changes a Buffer in place or grows it: fill, write, copy,
 * set, append/prepend and the byte-order swaps.
 */
final class MutationTest extends TestCase
{
    public function testFillWithInt(): void
    {
        $this->assertSame('ABABABAB', Buffer::alloc(4)->fill(0xAB)->toString('hex'));
        $this->assertSame('00ABAB00', Buffer::alloc(4)->fill(0xAB, 1, 3)->toString('hex'));
    }

    public function testFillWithStringRepeatsThePattern(): void
    {
        $this->assertSame('616261626162', Buffer::alloc(6)->fill('ab')->toString('hex'));
        $this->assertSame('6162616261', Buffer::alloc(5)->fill('ab')->toString('hex'), 'truncated mid-pattern');
    }

    public function testFillWithBuffer(): void
    {
        $this->assertSame('DEADDEAD', Buffer::alloc(4)->fill(Buffer::from('DEAD', 'hex'))->toString('hex'));
    }

    public function testFillWithEmptyStringZeroesTheRange(): void
    {
        $this->assertSame('00000000', Buffer::alloc(4, 0xFF)->fill('')->toString('hex'));
    }

    public function testFillIgnoresOutOfRangeArguments(): void
    {
        $this->assertSame('FFFFFFFF', Buffer::alloc(4, 0xFF)->fill(0x00, -1)->toString('hex'));
        $this->assertSame('FFFFFFFF', Buffer::alloc(4, 0xFF)->fill(0x00, 4)->toString('hex'));
        $this->assertSame('FFFFFFFF', Buffer::alloc(4, 0xFF)->fill(0x00, 2, 1)->toString('hex'));
        $this->assertSame('FFFFFFFF', Buffer::alloc(4, 0xFF)->fill(0x00, 0, 99)->toString('hex'));
    }

    public function testFillIsChainable(): void
    {
        $buf = Buffer::alloc(4);
        $this->assertSame($buf, $buf->fill(0x01));
    }

    public function testWrite(): void
    {
        $buf = Buffer::alloc(8);
        $written = $buf->write('abc');

        $this->assertSame(3, $written);
        $this->assertSame('6162630000000000', $buf->toString('hex'));
    }

    public function testWriteAtOffsetAndWithLengthLimit(): void
    {
        $buf = Buffer::alloc(8);
        $this->assertSame(3, $buf->write('abc', 2));
        $this->assertSame('0000616263000000', $buf->toString('hex'));

        $buf = Buffer::alloc(8);
        $this->assertSame(2, $buf->write('abcdef', 0, 2));
        $this->assertSame('6162000000000000', $buf->toString('hex'));
    }

    public function testWriteTruncatesAtTheBufferEnd(): void
    {
        $buf = Buffer::alloc(4);
        $this->assertSame(2, $buf->write('abcdef', 2));
        $this->assertSame('00006162', $buf->toString('hex'));
    }

    public function testWriteBeyondTheEndIsANoOp(): void
    {
        $buf = Buffer::alloc(4, 0xFF);
        $this->assertSame(0, $buf->write('abc', 4));
        $this->assertSame('FFFFFFFF', $buf->toString('hex'));
    }

    public function testWriteWithEncoding(): void
    {
        $buf = Buffer::alloc(4);
        $this->assertSame(2, $buf->write('DEAD', 0, null, 'hex'));
        $this->assertSame('DEAD0000', $buf->toString('hex'));
    }

    public function testCopy(): void
    {
        $source = Buffer::from('0102030405', 'hex');
        $target = Buffer::alloc(5, 0xFF);

        $this->assertSame(5, $source->copy($target));
        $this->assertSame('0102030405', $target->toString('hex'));
    }

    public function testCopyWithOffsetsAndRange(): void
    {
        $source = Buffer::from('0102030405', 'hex');
        $target = Buffer::alloc(5, 0xFF);

        $this->assertSame(2, $source->copy($target, 1, 2, 4));
        $this->assertSame('FF0304FFFF', $target->toString('hex'));
    }

    public function testCopyIsLimitedByTheSmallerRegion(): void
    {
        $source = Buffer::from('0102030405', 'hex');
        $target = Buffer::alloc(2);

        $this->assertSame(2, $source->copy($target));
        $this->assertSame('0102', $target->toString('hex'));
    }

    public function testCopyOutOfRangeReturnsZero(): void
    {
        $source = Buffer::from('0102', 'hex');
        $target = Buffer::alloc(2, 0xFF);

        $this->assertSame(0, $source->copy($target, 2));
        $this->assertSame(0, $source->copy($target, 0, 2));
        $this->assertSame('FFFF', $target->toString('hex'));
    }

    public function testCopyHandlesOverlappingRegionsWithinTheSameBuffer(): void
    {
        $buf = Buffer::from('0102030405', 'hex');
        $buf->copy($buf, 1, 0, 4);

        $this->assertSame('0101020304', $buf->toString('hex'), 'forward overlap must not smear bytes');
    }

    public function testSetWithinBounds(): void
    {
        $buf = Buffer::alloc(4, 0xFF);
        $buf->set(1, [0x01, 0x02]);

        $this->assertSame('FF0102FF', $buf->toString('hex'));
    }

    public function testSetGrowsTheBuffer(): void
    {
        $buf = Buffer::from('AABB', 'hex');
        $buf->set(2, [0xCC, 0xDD]);

        $this->assertSame('AABBCCDD', $buf->toString('hex'));
        $this->assertSame(4, $buf->getLength());
    }

    public function testSetMasksValues(): void
    {
        $buf = Buffer::alloc(2);
        $buf->set(0, [256, -1]);

        $this->assertSame('00FF', $buf->toString('hex'));
    }

    public function testAppendBuffer(): void
    {
        $buf = Buffer::from('AABB', 'hex');
        $buf->appendBuffer(Buffer::from('CCDD', 'hex'));

        $this->assertSame('AABBCCDD', $buf->toString('hex'));
        $this->assertSame(4, $buf->getLength());
    }

    public function testAppendHex(): void
    {
        $buf = Buffer::from('AABB', 'hex');
        $buf->appendHex('CCDD');

        $this->assertSame('AABBCCDD', $buf->toString('hex'));
    }

    public function testPrependBuffer(): void
    {
        $buf = Buffer::from('CCDD', 'hex');
        $buf->prependBuffer(Buffer::from('AABB', 'hex'));

        $this->assertSame('AABBCCDD', $buf->toString('hex'));
        $this->assertSame(4, $buf->getLength());
    }

    public function testPrependHex(): void
    {
        $buf = Buffer::from('CCDD', 'hex');
        $buf->prependHex('AABB');

        $this->assertSame('AABBCCDD', $buf->toString('hex'));
    }

    public function testAppendAndPrependEmptyBuffers(): void
    {
        $buf = Buffer::from('AABB', 'hex');
        $buf->appendBuffer(Buffer::alloc(0));
        $buf->prependBuffer(Buffer::alloc(0));

        $this->assertSame('AABB', $buf->toString('hex'));
        $this->assertSame(2, $buf->getLength());
    }

    public function testSwap16(): void
    {
        $this->assertSame('0201', Buffer::from('0102', 'hex')->swap16()->toString('hex'));
        $this->assertSame('02010403', Buffer::from('01020304', 'hex')->swap16()->toString('hex'));
        $this->assertSame('', Buffer::alloc(0)->swap16()->toString('hex'));
    }

    public function testSwap32(): void
    {
        $this->assertSame('04030201', Buffer::from('01020304', 'hex')->swap32()->toString('hex'));
    }

    public function testSwap64(): void
    {
        $this->assertSame('0807060504030201', Buffer::from('0102030405060708', 'hex')->swap64()->toString('hex'));
    }

    public function testSwapsAreChainable(): void
    {
        $buf = Buffer::from('0102', 'hex');
        $this->assertSame($buf, $buf->swap16());
    }

    /**
     * @dataProvider badSwapProvider
     */
    public function testSwapRejectsMisalignedLength(string $method, string $hex, string $message): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage($message);
        Buffer::from($hex, 'hex')->$method();
    }

    public static function badSwapProvider(): array
    {
        return [
            ['swap16', '010203', 'Buffer size must be a multiple of 16-bits'],
            ['swap32', '01020304050607', 'Buffer size must be a multiple of 32-bits'],
            ['swap64', '010203040506070809', 'Buffer size must be a multiple of 64-bits'],
        ];
    }
}
