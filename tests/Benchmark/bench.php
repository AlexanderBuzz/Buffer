<?php declare(strict_types=1);
/**
 * Measures the hot paths identified in docs/RELEASE-2.0-PLAN.md.
 *
 * Absolute numbers only -- the 1.x comparison that justified the string
 * backing is recorded in docs/SPIKE-string-backing.md.
 *
 *   php tests/Benchmark/bench.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Hardcastle\Buffer\Buffer;

function bench(callable $fn, int $iters): float
{
    $fn(); // warm
    $start = hrtime(true);
    for ($i = 0; $i < $iters; $i++) {
        $fn();
    }

    return (hrtime(true) - $start) / 1e6;
}

$hex20 = str_repeat('A7', 20);    // account id
$hex32 = str_repeat('3C', 32);    // hash / ledger entry
$hex256 = str_repeat('5E', 256);  // transaction blob

$buf32 = Buffer::from($hex32, 'hex');
$buf256 = Buffer::from($hex256, 'hex');
$list = [Buffer::from($hex20, 'hex'), Buffer::from($hex32, 'hex'), Buffer::from('0102030405060708', 'hex')];

$cases = [
    ['from(hex, 20B)', 200000, static fn (): Buffer => Buffer::from($hex20, 'hex')],
    ['from(hex, 32B)', 200000, static fn (): Buffer => Buffer::from($hex32, 'hex')],
    ['from(hex, 256B)', 50000, static fn (): Buffer => Buffer::from($hex256, 'hex')],
    ['from(Buffer) clone 32B', 200000, static fn (): Buffer => Buffer::from($buf32)],
    ['toString(hex, 32B)', 200000, static fn (): string => $buf32->toString('hex')],
    ['toString(hex, 256B)', 50000, static fn (): string => $buf256->toString('hex')],
    ['toUtf8(256B)', 50000, static fn (): string => $buf256->toUtf8()],
    ['toArray(32B)', 200000, static fn (): array => $buf32->toArray()],
    ['slice(4,20) of 32B', 200000, static fn (): Buffer => $buf32->slice(4, 20)],
    ['alloc(32)', 200000, static fn (): Buffer => Buffer::alloc(32)],
    ['getLength()', 500000, static fn (): int => $buf32->getLength()],
    ['toInt(8B)', 100000, static fn (): int => Buffer::from('0102030405060708', 'hex')->toInt()],
    ['concat(3 bufs, 60B)', 100000, static fn (): Buffer => Buffer::concat($list)],
    ['readUInt8 x32', 100000, static function () use ($buf32): int {
        $sum = 0;
        for ($i = 0; $i < 32; $i++) {
            $sum += $buf32->readUInt8($i);
        }
        return $sum;
    }],
    ['CODEC: 12-field tx serialise', 20000, static function (): array {
        $parts = [];
        foreach ([2, 4, 8, 20, 32, 8, 4, 20, 32, 2, 8, 64] as $size) {
            $parts[] = Buffer::from(str_repeat('5A', $size), 'hex');
        }
        $tx = Buffer::concat($parts);
        return [$tx->toString('hex'), $tx->slice(0, 32)->toString('hex')];
    }],
];

printf("PHP %s\n\n", PHP_VERSION);
printf("%-32s %9s %10s %14s\n", 'operation', 'iters', 'total ms', 'ops/sec');
printf("%s\n", str_repeat('-', 69));
foreach ($cases as [$name, $iters, $fn]) {
    $ms = bench($fn, $iters);
    printf("%-32s %9d %10.1f %14s\n", $name, $iters, $ms, number_format($iters / ($ms / 1000)));
}

$before = memory_get_usage();
$keep = [];
for ($i = 0; $i < 1000; $i++) {
    $keep[] = Buffer::from($hex32, 'hex');
}
$used = memory_get_usage() - $before;
printf("\n1000 buffers of 32 bytes: %.1f KB (%.1f bytes each)\n", $used / 1024, $used / 1000);
