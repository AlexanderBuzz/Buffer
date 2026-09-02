<?php declare(strict_types=1);

namespace Hardcastle\Buffer\Test\Characterization;

use Hardcastle\Buffer\Buffer;
use PHPUnit\Framework\TestCase;

/**
 * indexOf/lastIndexOf/includes and the comparison family.
 */
final class SearchAndCompareTest extends TestCase
{
    private function haystack(): Buffer
    {
        return Buffer::from('0102030102030102', 'hex');
    }

    public function testIndexOfBuffer(): void
    {
        $this->assertSame(0, $this->haystack()->indexOf(Buffer::from('0102', 'hex')));
        $this->assertSame(2, $this->haystack()->indexOf(Buffer::from('0301', 'hex')));
        $this->assertSame(-1, $this->haystack()->indexOf(Buffer::from('FFFF', 'hex')));
    }

    public function testIndexOfInt(): void
    {
        $this->assertSame(0, $this->haystack()->indexOf(0x01));
        $this->assertSame(2, $this->haystack()->indexOf(0x03));
        $this->assertSame(-1, $this->haystack()->indexOf(0xFF));
    }

    public function testIndexOfString(): void
    {
        $buf = Buffer::from('hello world');

        $this->assertSame(0, $buf->indexOf('hello'));
        $this->assertSame(6, $buf->indexOf('world'));
        $this->assertSame(-1, $buf->indexOf('missing'));
    }

    public function testIndexOfWithEncoding(): void
    {
        $this->assertSame(2, $this->haystack()->indexOf('0301', 0, 'hex'));
    }

    public function testIndexOfRespectsByteOffset(): void
    {
        $this->assertSame(0, $this->haystack()->indexOf(Buffer::from('0102', 'hex'), 0));
        $this->assertSame(3, $this->haystack()->indexOf(Buffer::from('0102', 'hex'), 1));
        $this->assertSame(6, $this->haystack()->indexOf(Buffer::from('0102', 'hex'), 4));
    }

    public function testIndexOfEmptyNeedleReturnsMinusOne(): void
    {
        $this->assertSame(-1, $this->haystack()->indexOf(Buffer::alloc(0)));
        $this->assertSame(-1, $this->haystack()->indexOf(''));
    }

    public function testIndexOfNeedleLongerThanHaystack(): void
    {
        $this->assertSame(-1, Buffer::from('01', 'hex')->indexOf(Buffer::from('0102', 'hex')));
    }

    public function testLastIndexOf(): void
    {
        $this->assertSame(6, $this->haystack()->lastIndexOf(Buffer::from('0102', 'hex')));
        $this->assertSame(6, $this->haystack()->lastIndexOf(0x01));
        $this->assertSame(-1, $this->haystack()->lastIndexOf(0xFF));
    }

    public function testLastIndexOfRespectsByteOffset(): void
    {
        $this->assertSame(3, $this->haystack()->lastIndexOf(Buffer::from('0102', 'hex'), 5));
        $this->assertSame(0, $this->haystack()->lastIndexOf(Buffer::from('0102', 'hex'), 2));
    }

    public function testLastIndexOfEmptyNeedleReturnsMinusOne(): void
    {
        $this->assertSame(-1, $this->haystack()->lastIndexOf(Buffer::alloc(0)));
    }

    public function testIncludes(): void
    {
        $this->assertTrue($this->haystack()->includes(Buffer::from('0203', 'hex')));
        $this->assertTrue($this->haystack()->includes(0x03));
        $this->assertFalse($this->haystack()->includes(0xFF));
        $this->assertFalse($this->haystack()->includes(Buffer::alloc(0)));
    }

    public function testEquals(): void
    {
        $this->assertTrue(Buffer::from('AABB', 'hex')->equals(Buffer::from('AABB', 'hex')));
        $this->assertFalse(Buffer::from('AABB', 'hex')->equals(Buffer::from('AABC', 'hex')));
        $this->assertFalse(Buffer::from('AABB', 'hex')->equals(Buffer::from('AABBCC', 'hex')));
        $this->assertTrue(Buffer::alloc(0)->equals(Buffer::alloc(0)));
    }

    public function testCompareToOrdersByFirstDifferingByte(): void
    {
        $this->assertSame(0, Buffer::from('AABB', 'hex')->compareTo(Buffer::from('AABB', 'hex')));
        $this->assertSame(-1, Buffer::from('AABB', 'hex')->compareTo(Buffer::from('AABC', 'hex')));
        $this->assertSame(1, Buffer::from('AABC', 'hex')->compareTo(Buffer::from('AABB', 'hex')));
    }

    public function testCompareToOrdersShorterBufferFirstWhenPrefixesMatch(): void
    {
        $this->assertSame(-1, Buffer::from('AA', 'hex')->compareTo(Buffer::from('AABB', 'hex')));
        $this->assertSame(1, Buffer::from('AABB', 'hex')->compareTo(Buffer::from('AA', 'hex')));
    }

    public function testCompareToWithExplicitRanges(): void
    {
        $a = Buffer::from('FFAABBFF', 'hex');
        $b = Buffer::from('00AABB00', 'hex');

        $this->assertSame(0, $a->compareTo($b, 1, 3, 1, 3), 'comparing only the matching middle');
    }

    public function testStaticCompare(): void
    {
        $this->assertSame(0, Buffer::compare(Buffer::from('AA', 'hex'), Buffer::from('AA', 'hex')));
        $this->assertSame(-1, Buffer::compare(Buffer::from('AA', 'hex'), Buffer::from('AB', 'hex')));
        $this->assertSame(1, Buffer::compare(Buffer::from('AB', 'hex'), Buffer::from('AA', 'hex')));
    }

    public function testCompareIsUsableAsASortCallback(): void
    {
        $buffers = [
            Buffer::from('CC', 'hex'),
            Buffer::from('AA', 'hex'),
            Buffer::from('BB', 'hex'),
        ];
        usort($buffers, Buffer::compare(...));

        $this->assertSame(['AA', 'BB', 'CC'], array_map(static fn (Buffer $b): string => $b->toString('hex'), $buffers));
    }
}
