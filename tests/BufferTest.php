<?php declare(strict_types=1);

namespace Hardcastle\Buffer\Test;

use Hardcastle\Buffer\Buffer;
use PHPUnit\Framework\TestCase;

final class BufferTest extends TestCase
{
    public function testAlloc(): void
    {
        $numElements = 20;
        $buf = Buffer::alloc($numElements);

        $this->assertEquals($numElements, $buf->getLength());
    }

    public function testFrom(): void
    {
        // From byte array
        $buf = Buffer::from([12, 108, 0, 230]);
        $exp = '0C6C00E6';
        $this->assertEquals($exp, $buf->toString());

        // From hex
        $buf = Buffer::from('ff03a5ed', 'hex');
        $exp = [255, 3, 165, 237];
        $this->assertEquals($exp, $buf->toArray());

        $buf = Buffer::from('f03a5ed', 'hex');
        $exp = [15, 3, 165, 237];
        $this->assertEquals($exp, $buf->toArray());

        // From base64
        $buf = Buffer::from('aGVsbG8gd29ybGQ=', 'base64');
        $exp = [104, 101, 108, 108, 111, 32, 119, 111, 114, 108, 100,];
        $this->assertEquals($exp, $buf->toArray());
        $exp = 'hello world';
        $this->assertEquals($exp, $buf->toUtf8());

        // From string, using default encoding 'utf-8'
        $buf = Buffer::from('hello world');
        $exp = 'hello world';
        $this->assertEquals($exp, $buf->toUtf8());

    }

    public function testOffsetGetSet(): void
    {
        $buf = Buffer::alloc(10);
        $buf[0] = 0x41; // 'A'
        $this->assertEquals(65, $buf[0]);
        $this->assertEquals(10, $buf->length);
    }

    public function testWriteAndToUtf8(): void
    {
        $buf = Buffer::alloc(10);
        $buf[0] = 0x41; // 'A'
        $buf->write('hello', 1);
        $this->assertStringStartsWith('Ahello', $buf->toUtf8());
    }
}