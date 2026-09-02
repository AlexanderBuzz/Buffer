<?php declare(strict_types=1);

namespace Hardcastle\Buffer\Test\Characterization;

use Hardcastle\Buffer\Buffer;
use PHPUnit\Framework\TestCase;

/**
 * subArray()/slice() across the full index grid. This is the method with the
 * widest surface for off-by-one and sign errors, so it gets its own class.
 */
final class SliceTest extends TestCase
{
    private const SOURCE = '000102030405060708090A0B0C0D0E0F'; // 16 bytes

    private function buf(): Buffer
    {
        return Buffer::from(self::SOURCE, 'hex');
    }

    public function testSliceIsAnAliasForSubArray(): void
    {
        $this->assertSame(
            $this->buf()->subArray(2, 6)->toString('hex'),
            $this->buf()->slice(2, 6)->toString('hex')
        );
    }

    /**
     * @dataProvider rangeProvider
     */
    public function testSubArrayRanges(int $start, ?int $end, string $expected): void
    {
        $this->assertSame($expected, $this->buf()->subArray($start, $end)->toString('hex'));
    }

    public static function rangeProvider(): array
    {
        return [
            'whole buffer'            => [0, null, self::SOURCE],
            'explicit whole buffer'   => [0, 16, self::SOURCE],
            'head'                    => [0, 4, '00010203'],
            'middle'                  => [4, 8, '04050607'],
            'tail via null end'       => [12, null, '0C0D0E0F'],
            'empty, start equals end' => [8, 8, ''],
            'empty, end before start' => [8, 4, ''],
            'end beyond length'       => [12, 99, '0C0D0E0F'],
            'negative start'          => [-4, null, '0C0D0E0F'],
            'negative end'            => [0, -12, '00010203'],
            'both negative'           => [-8, -4, '08090A0B'],
            'negative start clamped'  => [-99, 4, '00010203'],
            'negative end clamped'    => [0, -99, ''],
            'single byte'             => [5, 6, '05'],
        ];
    }

    public function testSubArrayReturnsACopyNotAView(): void
    {
        // Deliberate deviation from Node, where slice() shares memory. See README.
        $original = $this->buf();
        $slice = $original->subArray(0, 4);
        $slice->writeUInt8(0xFF, 0);

        $this->assertSame(self::SOURCE, $original->toString('hex'), 'mutating the slice must not touch the original');
        $this->assertSame('FF010203', $slice->toString('hex'));
    }

    public function testSubArrayOfEmptyBuffer(): void
    {
        $this->assertSame('', Buffer::alloc(0)->subArray(0)->toString('hex'));
        $this->assertSame('', Buffer::alloc(0)->subArray(0, 0)->toString('hex'));
    }

    public function testSubArrayLengthMatchesContent(): void
    {
        foreach ([[0, 4], [4, 12], [0, 16], [8, 8]] as [$start, $end]) {
            $slice = $this->buf()->subArray($start, $end);
            $this->assertSame(
                $slice->getLength() * 2,
                strlen($slice->toString('hex')),
                "subArray($start, $end): length and content must agree"
            );
        }
    }
}
