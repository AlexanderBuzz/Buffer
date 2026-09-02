<?php declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';
use Hardcastle\Buffer\Buffer;
function b(callable $f, int $n): float { $f(); $t=hrtime(true); for($i=0;$i<$n;$i++){$f();} return (hrtime(true)-$t)/1e6; }
function run(string $label, int $n, callable $fn): void {
    $ms = b($fn, $n);
    printf("%-46s %9d %10.1f %13s\n", $label, $n, $ms, number_format($n / ($ms / 1000)));
}
printf("%-46s %9s %10s %13s\n", 'workload', 'iters', 'total ms', 'ops/sec');
echo str_repeat('-',81),"\n";

run('toArray-heavy address decode', 50000, function () {
    $buf = Buffer::from(str_repeat('9F', 25), 'hex');
    return array_merge($buf->slice(0, 2)->toArray(), $buf->slice(2, 22)->toArray(), $buf->toArray());
});

run('Amount-shaped field assembly (concat)', 50000, function () {
    $amount = Buffer::from(str_repeat('11', 8), 'hex');
    $currency = Buffer::from(str_repeat('22', 20), 'hex');
    $issuer = Buffer::from(str_repeat('33', 20), 'hex');
    return Buffer::concat([$amount, $currency, $issuer])->toString('hex');
});

run('  ...same via array_merge(toArray) detour', 50000, function () {
    $amount = Buffer::from(str_repeat('11', 8), 'hex');
    $currency = Buffer::from(str_repeat('22', 20), 'hex');
    $issuer = Buffer::from(str_repeat('33', 20), 'hex');
    return Buffer::from(array_merge($amount->toArray(), $currency->toArray(), $issuer->toArray()))->toString('hex');
});

run('BinaryParser-shaped read loop (64 fields)', 20000, function () {
    $buf = Buffer::from(str_repeat('4D', 512), 'hex');
    $out = [];
    $offset = 0;
    for ($i = 0; $i < 64; $i++) {
        $out[] = $buf->readUInt8($offset);
        $out[] = $buf->slice($offset, $offset + 8)->toString('hex');
        $offset += 8;
    }
    return $out;
});
