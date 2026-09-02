<?php declare(strict_types=1);

namespace Hardcastle\Buffer\Test\Characterization;

use Brick\Math\BigInteger;
use Exception;
use Hardcastle\Buffer\Buffer;
use PHPUnit\Framework\TestCase;

/**
 * Locks down how Buffers come into existence: alloc, from() across every
 * supported source type, concat, and the static helpers.
 */
final class ConstructionTest extends TestCase
{
    public function testAllocProducesZeroFilledBufferOfGivenSize(): void
    {
        foreach ([0, 1, 8, 32, 257] as $size) {
            $buf = Buffer::alloc($size);
            $this->assertSame($size, $buf->getLength(), "alloc($size) length");
            $this->assertSame(str_repeat('00', $size), $buf->toString('hex'), "alloc($size) content");
        }
    }

    public function testAllocWithFill(): void
    {
        $this->assertSame('ABABABAB', Buffer::alloc(4, 0xAB)->toString('hex'));
        $this->assertSame('616263616263', Buffer::alloc(6, 'abc')->toString('hex'));
        $this->assertSame('61626361', Buffer::alloc(4, 'abc')->toString('hex'), 'fill pattern repeats and truncates');
        $this->assertSame('DEADDEAD', Buffer::alloc(4, Buffer::from('DEAD', 'hex'))->toString('hex'));
    }

    public function testFromHex(): void
    {
        $this->assertSame([255, 3, 165, 237], Buffer::from('ff03a5ed', 'hex')->toArray());
        $this->assertSame('FF03A5ED', Buffer::from('ff03a5ed', 'hex')->toString('hex'), 'output is upper case');
        $this->assertSame(0, Buffer::from('', 'hex')->getLength());
    }

    public function testFromHexPadsOddLengthOnTheLeft(): void
    {
        $this->assertSame([15, 3, 165, 237], Buffer::from('f03a5ed', 'hex')->toArray());
        $this->assertSame('0F', Buffer::from('F', 'hex')->toString('hex'));
    }

    public function testFromHexStripsPrefixAndSeparators(): void
    {
        $this->assertSame('DEAD', Buffer::from('0xDEAD', 'hex')->toString('hex'));
        $this->assertSame('DEAD', Buffer::from('0XDEAD', 'hex')->toString('hex'));
        $this->assertSame('DEADBEEF', Buffer::from('DE:AD BE-EF', 'hex')->toString('hex'), 'non-hex characters are dropped');
    }

    public function testFromByteArrayMasksToOneByte(): void
    {
        $this->assertSame('0C6C00E6', Buffer::from([12, 108, 0, 230])->toString('hex'));
        $this->assertSame('FF00FF', Buffer::from([255, 256, -1])->toString('hex'), 'values are masked with 0xFF');
        $this->assertSame(0, Buffer::from([])->getLength());
    }

    public function testFromString(): void
    {
        $this->assertSame('68656C6C6F', Buffer::from('hello')->toString('hex'));
        $this->assertSame('hello', Buffer::from('hello')->toUtf8());
        $this->assertSame(0, Buffer::from('')->getLength());
    }

    public function testFromStringIsByteWiseNotCharacterWise(): void
    {
        $buf = Buffer::from('Grüße');
        $this->assertSame(strlen('Grüße'), $buf->getLength(), 'length counts bytes, not characters');
        $this->assertSame('Grüße', $buf->toUtf8());
    }

    public function testFromBase64(): void
    {
        $buf = Buffer::from('aGVsbG8gd29ybGQ=', 'base64');
        $this->assertSame('hello world', $buf->toUtf8());
        $this->assertSame(11, $buf->getLength());
    }

    public function testFromBuffer(): void
    {
        $source = Buffer::from('DEADBEEF', 'hex');
        $copy = Buffer::from($source);

        $this->assertSame($source->toString('hex'), $copy->toString('hex'));
        $this->assertNotSame($source, $copy);
    }

    public function testFromBufferCopiesRatherThanReferences(): void
    {
        $source = Buffer::from('DEADBEEF', 'hex');
        $copy = Buffer::from($source);
        $copy->writeUInt8(0x00, 0);

        $this->assertSame('DEADBEEF', $source->toString('hex'), 'mutating the copy must not touch the source');
        $this->assertSame('00ADBEEF', $copy->toString('hex'));
    }

    public function testFromBigInteger(): void
    {
        $this->assertSame('FF', Buffer::from(BigInteger::of(255))->toString('hex'));
        $this->assertSame('0100', Buffer::from(BigInteger::of(256))->toString('hex'));
    }

    public function testFromUnsupportedSourceThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Buffer does not support source type');
        Buffer::from(3.14);
    }

    public function testCloneIsAnIndependentCopy(): void
    {
        $source = Buffer::from('0102', 'hex');
        $clone = $source->clone();
        $clone->writeUInt8(0xFF, 0);

        $this->assertSame('0102', $source->toString('hex'));
        $this->assertSame('FF02', $clone->toString('hex'));
    }

    public function testConcat(): void
    {
        $parts = [Buffer::from('AABB', 'hex'), Buffer::from('CC', 'hex'), Buffer::from('', 'hex')];

        $this->assertSame('AABBCC', Buffer::concat($parts)->toString('hex'));
        $this->assertSame(0, Buffer::concat([])->getLength());
    }

    public function testConcatTruncatesToTotalLength(): void
    {
        $parts = [Buffer::from('AABB', 'hex'), Buffer::from('CCDD', 'hex')];

        $this->assertSame('AABB', Buffer::concat($parts, 2)->toString('hex'));
        $this->assertSame('AABBCC', Buffer::concat($parts, 3)->toString('hex'));
        $this->assertSame(0, Buffer::concat($parts, 0)->getLength());
    }

    public function testRandom(): void
    {
        $this->assertSame(16, Buffer::random(16)->getLength());
        $this->assertSame(0, Buffer::random(0)->getLength());
        $this->assertNotSame(Buffer::random(32)->toString('hex'), Buffer::random(32)->toString('hex'));
    }

    public function testByteLength(): void
    {
        $this->assertSame(4, Buffer::byteLength('abcd'));
        $this->assertSame(2, Buffer::byteLength('abcd', 'hex'));
        $this->assertSame(2, Buffer::byteLength('abc=', 'base64'));
        $this->assertSame(0, Buffer::byteLength(''));
    }

    public function testIsBuffer(): void
    {
        $this->assertTrue(Buffer::isBuffer(Buffer::alloc(10)));
        $this->assertFalse(Buffer::isBuffer([]));
        $this->assertFalse(Buffer::isBuffer('AABB'));
        $this->assertFalse(Buffer::isBuffer(null));
    }

    public function testIsEncoding(): void
    {
        foreach (['hex', 'utf8', 'utf-8', 'base64', 'ascii', 'latin1', 'binary', 'HEX'] as $encoding) {
            $this->assertTrue(Buffer::isEncoding($encoding), "$encoding should be supported");
        }
        $this->assertFalse(Buffer::isEncoding('invalid'));
        $this->assertFalse(Buffer::isEncoding('utf16'));
    }
}
