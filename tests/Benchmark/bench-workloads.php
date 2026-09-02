<?php declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/BufferStr.php';
use Hardcastle\Buffer\Buffer as A;
use Bench\BufferStr as B;
function b(callable $f, int $n): float { $f(); $t=hrtime(true); for($i=0;$i<$n;$i++){$f();} return (hrtime(true)-$t)/1e6; }
function cmp(string $label, int $n, callable $o, callable $w) {
    $a = b($o,$n); $c = b($w,$n);
    printf("%-46s %9.1f %9.1f %8.2fx\n", $label, $a, $c, $a/max($c,1e-9));
}
printf("%-46s %9s %9s %8s\n", 'workload', 'SplFixed', 'string', 'speedup');
echo str_repeat('-',76),"\n";

// Worst case for string backing: toArray-heavy, mirrors AddressCodec/Amount patterns
cmp('WORST CASE: toArray-heavy address decode', 50000,
  function () {
    $buf = A::from(str_repeat('9F',25), 'hex');
    $prefix  = $buf->slice(0, 2)->toArray();
    $payload = $buf->slice(2, 22)->toArray();
    $all     = $buf->toArray();
    return array_merge($prefix, $payload, $all);
  },
  function () {
    $buf = B::from(str_repeat('9F',25), 'hex');
    $prefix  = $buf->slice(0, 2)->toArray();
    $payload = $buf->slice(2, 22)->toArray();
    $all     = $buf->toArray();
    return array_merge($prefix, $payload, $all);
  });

// The Amount.php:156 pattern, as written today (array_merge round-trip)
cmp('Amount::from(array_merge(toArray x3)) as-is', 50000,
  function () {
    $am = A::from(str_repeat('11',8),'hex'); $cu = A::from(str_repeat('22',20),'hex'); $is = A::from(str_repeat('33',20),'hex');
    return A::from(array_merge($am->toArray(), $cu->toArray(), $is->toArray()))->toString('hex');
  },
  function () {
    $am = B::from(str_repeat('11',8),'hex'); $cu = B::from(str_repeat('22',20),'hex'); $is = B::from(str_repeat('33',20),'hex');
    return B::from(array_merge($am->toArray(), $cu->toArray(), $is->toArray()))->toString('hex');
  });

// Same result, rewritten as concat() - what string backing makes natural
cmp('  ...same, rewritten as concat()', 50000,
  function () {
    $am = A::from(str_repeat('11',8),'hex'); $cu = A::from(str_repeat('22',20),'hex'); $is = A::from(str_repeat('33',20),'hex');
    return A::concat([$am,$cu,$is])->toString('hex');
  },
  function () {
    $am = B::from(str_repeat('11',8),'hex'); $cu = B::from(str_repeat('22',20),'hex'); $is = B::from(str_repeat('33',20),'hex');
    return B::concat([$am,$cu,$is])->toString('hex');
  });

// Round trip through the codec shape: parse fields out of a blob byte-wise
cmp('BinaryParser-shaped read loop (64 fields)', 20000,
  function () {
    $buf = A::from(str_repeat('4D',512),'hex'); $out = []; $off = 0;
    for ($i=0;$i<64;$i++) { $out[] = $buf->readUInt8($off); $out[] = $buf->slice($off,$off+8)->toString('hex'); $off += 8; }
    return $out;
  },
  function () {
    $buf = B::from(str_repeat('4D',512),'hex'); $out = []; $off = 0;
    for ($i=0;$i<64;$i++) { $out[] = $buf->readUInt8($off); $out[] = $buf->slice($off,$off+8)->toString('hex'); $off += 8; }
    return $out;
  });
