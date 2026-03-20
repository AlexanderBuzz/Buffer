<?php declare(strict_types=1);

namespace Hardcastle\Buffer\Test;

use Hardcastle\Buffer\Buffer;
use PHPUnit\Framework\TestCase;

final class BufferExtendedTest extends TestCase
{
    public function testStaticMethods(): void
    {
        $this->assertTrue(Buffer::isBuffer(Buffer::alloc(10)));
        $this->assertFalse(Buffer::isBuffer([]));

        $this->assertTrue(Buffer::isEncoding('hex'));
        $this->assertTrue(Buffer::isEncoding('utf8'));
        $this->assertTrue(Buffer::isEncoding('base64'));
        $this->assertFalse(Buffer::isEncoding('invalid'));

        $this->assertEquals(4, Buffer::byteLength('abcd'));
        $this->assertEquals(2, Buffer::byteLength('abcd', 'hex'));
        $this->assertEquals(2, Buffer::byteLength('abc=', 'base64'));
    }

    public function testFill(): void
    {
        $buf = Buffer::alloc(5);
        $buf->fill(0xCF);
        $this->assertEquals([0xCF, 0xCF, 0xCF, 0xCF, 0xCF], $buf->toArray());

        $buf->fill('abc');
        $this->assertEquals([ord('a'), ord('b'), ord('c'), ord('a'), ord('b')], $buf->toArray());

        $buf->fill(0, 1, 3);
        $this->assertEquals([ord('a'), 0, 0, ord('a'), ord('b')], $buf->toArray());
    }

    public function testWrite(): void
    {
        $buf = Buffer::alloc(10);
        $len = $buf->write('hello');
        $this->assertEquals(5, $len);
        $this->assertEquals('hello', substr($buf->toUtf8(), 0, 5));

        $buf->write('world', 5);
        $this->assertEquals('helloworld', $buf->toUtf8());
    }

    public function testCopy(): void
    {
        $buf1 = Buffer::from('hello');
        $buf2 = Buffer::alloc(5);
        $buf1->copy($buf2);
        $this->assertEquals('hello', $buf2->toUtf8());

        $buf3 = Buffer::from('abcdefghij');
        $buf3->copy($buf3, 2, 0, 2); // copy 'ab' to index 2
        $this->assertEquals('ababefghij', $buf3->toUtf8());
    }

    public function testCompare(): void
    {
        $buf1 = Buffer::from('abc');
        $buf2 = Buffer::from('abd');
        $buf3 = Buffer::from('abc');

        $this->assertEquals(-1, $buf1->compareTo($buf2));
        $this->assertEquals(1, $buf2->compareTo($buf1));
        $this->assertEquals(0, $buf1->compareTo($buf3));
        $this->assertTrue($buf1->equals($buf3));
    }

    public function testSearch(): void
    {
        $buf = Buffer::from('hello world hello');
        $this->assertEquals(0, $buf->indexOf('hello'));
        $this->assertEquals(6, $buf->indexOf('world'));
        $this->assertEquals(12, $buf->lastIndexOf('hello'));
        $this->assertTrue($buf->includes('world'));
        $this->assertFalse($buf->includes('foo'));
    }

    public function testSwap(): void
    {
        $buf16 = Buffer::from([0x12, 0x34, 0x56, 0x78]);
        $buf16->swap16();
        $this->assertEquals([0x34, 0x12, 0x78, 0x56], $buf16->toArray());

        $buf32 = Buffer::from([0x12, 0x34, 0x56, 0x78]);
        $buf32->swap32();
        $this->assertEquals([0x78, 0x56, 0x34, 0x12], $buf32->toArray());
    }

    public function testReadWriteInt(): void
    {
        $buf = Buffer::alloc(8);
        
        $buf->writeInt8(-50, 0);
        $this->assertEquals(-50, $buf->readInt8(0));
        
        $buf->writeUInt8(200, 1);
        $this->assertEquals(200, $buf->readUInt8(1));

        $buf->writeInt16BE(-1000, 2);
        $this->assertEquals(-1000, $buf->readInt16BE(2));

        $buf->writeInt32LE(123456789, 4);
        $this->assertEquals(123456789, $buf->readInt32LE(4));
    }

    public function testReadWriteFloat(): void
    {
        $buf = Buffer::alloc(16);
        
        $buf->writeFloatBE(1.23, 0);
        $this->assertEqualsWithDelta(1.23, $buf->readFloatBE(0), 0.0001);

        $buf->writeDoubleLE(123.456, 4);
        $this->assertEqualsWithDelta(123.456, $buf->readDoubleLE(4), 0.0001);
    }

    public function testArrayAccess(): void
    {
        $buf = Buffer::alloc(5);
        $buf[0] = 10;
        $buf[1] = 20;
        $this->assertEquals(10, $buf[0]);
        $this->assertEquals(20, $buf[1]);
        
        $buf[10] = 100; // should expand
        $this->assertEquals(11, $buf->length);
        $this->assertEquals(100, $buf[10]);
        $this->assertEquals(0, $buf[5]);
    }
}
