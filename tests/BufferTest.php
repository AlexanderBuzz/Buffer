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

        // From string - 'hello world'

        // From Bricks/BigInteger
    }

    /*
    public function testSwap16(): void
    {
        $buf = Buffer::from([0x12, 0x34]);
        $exp = '3412';
        $this->assertEquals($exp, $buf->swap16()->toString());
    }
    */

    public function testToUtf8(): void
    {
        $elephantBuf = Buffer::from([240, 159, 144, 152]);
        $elephantStr = '🐘';

        $this->assertEquals($elephantStr, $elephantBuf->toUtf8());

        $loremBuf = Buffer::from([
            76, 111, 114, 101, 109, 32, 105, 112, 115, 117,
            109, 32, 100, 111, 108, 111, 114, 32, 115, 105,
            116, 32, 97, 109, 101, 116, 46, 46, 46,
        ]);
        $loremStr = 'Lorem ipsum dolor sit amet...';

        $this->assertEquals($loremStr, $loremBuf->toUtf8());
    }
}