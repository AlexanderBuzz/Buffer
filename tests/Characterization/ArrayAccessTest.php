<?php declare(strict_types=1);

namespace Hardcastle\Buffer\Test\Characterization;

use Exception;
use Hardcastle\Buffer\Buffer;
use PHPUnit\Framework\TestCase;
use SplFixedArray;

/**
 * ArrayAccess and the accessors that expose the internal byte storage.
 */
final class ArrayAccessTest extends TestCase
{
    public function testOffsetGet(): void
    {
        $buf = Buffer::from('00FF80', 'hex');

        $this->assertSame(0, $buf[0]);
        $this->assertSame(255, $buf[1]);
        $this->assertSame(128, $buf[2]);
    }

    public function testOffsetGetOutOfBoundsThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Requested Buffer element out of bounds');

        $buf = Buffer::from('AABB', 'hex');
        $buf[2];
    }

    public function testOffsetExists(): void
    {
        $buf = Buffer::from('AABB', 'hex');

        $this->assertTrue(isset($buf[0]));
        $this->assertTrue(isset($buf[1]));
        $this->assertFalse(isset($buf[2]));
        $this->assertFalse(isset($buf[-1]));
    }

    public function testOffsetSetWithinBounds(): void
    {
        $buf = Buffer::from('AABB', 'hex');
        $buf[0] = 0x01;

        $this->assertSame('01BB', $buf->toString('hex'));
        $this->assertSame(2, $buf->getLength());
    }

    public function testOffsetSetMasksValues(): void
    {
        $buf = Buffer::alloc(2);
        $buf[0] = 256;
        $buf[1] = -1;

        $this->assertSame('00FF', $buf->toString('hex'));
    }

    public function testOffsetSetIgnoresNonIntegerAndNegativeOffsets(): void
    {
        $buf = Buffer::from('AABB', 'hex');
        $buf['x'] = 0x01;
        $buf[-1] = 0x01;

        $this->assertSame('AABB', $buf->toString('hex'));
        $this->assertSame(2, $buf->getLength());
    }

    public function testIterationOverIndices(): void
    {
        $buf = Buffer::from('010203', 'hex');
        $collected = [];
        for ($i = 0; $i < $buf->getLength(); $i++) {
            $collected[] = $buf[$i];
        }

        $this->assertSame([1, 2, 3], $collected);
    }

    public function testGetBytesArray(): void
    {
        $buf = Buffer::from('0102FF', 'hex');
        $bytes = $buf->getBytesArray();

        $this->assertInstanceOf(SplFixedArray::class, $bytes);
        $this->assertSame(3, $bytes->getSize());
        $this->assertSame([1, 2, 255], $bytes->toArray());
    }

    public function testSetBytesArray(): void
    {
        $buf = Buffer::alloc(0);
        $buf->setBytesArray(SplFixedArray::fromArray([1, 2, 255]));

        $this->assertSame('0102FF', $buf->toString('hex'));
        $this->assertSame(3, $buf->getLength());
    }

    public function testBytesArrayRoundTrip(): void
    {
        $original = Buffer::from('DEADBEEF', 'hex');
        $restored = Buffer::alloc(0);
        $restored->setBytesArray($original->getBytesArray());

        $this->assertSame($original->toString('hex'), $restored->toString('hex'));
        $this->assertSame($original->getLength(), $restored->getLength());
    }
}
