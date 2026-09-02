<?php declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/BufferStr.php';
use Hardcastle\Buffer\Buffer as A;
use Bench\BufferStr as B;

function bench(callable $fn, int $iters): float {
    $fn(); // warm
    $t = hrtime(true);
    for ($i = 0; $i < $iters; $i++) { $fn(); }
    return (hrtime(true) - $t) / 1e6; // ms
}

$hex20  = str_repeat('A7', 20);   // account id
$hex32  = str_repeat('3C', 32);   // hash / ledger entry
$hex256 = str_repeat('5E', 256);  // tx blob
$rows = [];

function row(string $name, int $iters, callable $old, callable $new) {
    global $rows;
    $o = bench($old, $iters);
    $n = bench($new, $iters);
    $rows[] = [$name, $iters, $o, $n, $o / max($n, 1e-9)];
}

row('from(hex, 20B)',   200000, fn() => A::from($GLOBALS['hex20'], 'hex'),  fn() => B::from($GLOBALS['hex20'], 'hex'));
row('from(hex, 32B)',   200000, fn() => A::from($GLOBALS['hex32'], 'hex'),  fn() => B::from($GLOBALS['hex32'], 'hex'));
row('from(hex, 256B)',   50000, fn() => A::from($GLOBALS['hex256'], 'hex'), fn() => B::from($GLOBALS['hex256'], 'hex'));

$a32 = A::from($hex32, 'hex');   $b32 = B::from($hex32, 'hex');
$a256 = A::from($hex256, 'hex'); $b256 = B::from($hex256, 'hex');

row('toString(hex, 32B)', 200000, fn() => $GLOBALS['a32']->toString('hex'),  fn() => $GLOBALS['b32']->toString('hex'));
row('toString(hex, 256B)', 50000, fn() => $GLOBALS['a256']->toString('hex'), fn() => $GLOBALS['b256']->toString('hex'));
row('from(Buffer) clone 32B', 200000, fn() => A::from($GLOBALS['a32']), fn() => B::from($GLOBALS['b32']));
row('slice(4,20) of 32B', 200000, fn() => $GLOBALS['a32']->slice(4, 20), fn() => $GLOBALS['b32']->slice(4, 20));
row('toUtf8(256B)',        50000, fn() => $GLOBALS['a256']->toUtf8(), fn() => $GLOBALS['b256']->toUtf8());
row('toArray(32B)',       200000, fn() => $GLOBALS['a32']->toArray(), fn() => $GLOBALS['b32']->toArray());
row('alloc(32)',          200000, fn() => A::alloc(32), fn() => B::alloc(32));
row('toInt(8B)',          100000, fn() => A::from('0102030405060708','hex')->toInt(), fn() => B::from('0102030405060708','hex')->toInt());
row('getLength()',        500000, fn() => $GLOBALS['a32']->getLength(), fn() => $GLOBALS['b32']->getLength());

$listA = [A::from($hex20,'hex'), A::from($hex32,'hex'), A::from('0102030405060708','hex')];
$listB = [B::from($hex20,'hex'), B::from($hex32,'hex'), B::from('0102030405060708','hex')];
row('concat(3 bufs, 60B)', 100000, fn() => A::concat($GLOBALS['listA']), fn() => B::concat($GLOBALS['listB']));

row('readUInt8 x32', 100000,
    function () { $b = $GLOBALS['a32']; $s = 0; for ($i=0;$i<32;$i++) { $s += $b->readUInt8($i); } return $s; },
    function () { $b = $GLOBALS['b32']; $s = 0; for ($i=0;$i<32;$i++) { $s += $b->readUInt8($i); } return $s; });

row('getBytesArray() [BC adapter]', 50000, fn() => $GLOBALS['a32']->getBytesArray(), fn() => $GLOBALS['b32']->getBytesArray());

// Composite: serialise a tx-shaped set of fields, then hex it — what the codec actually does
row('CODEC: 12-field tx serialise', 20000,
    function () {
        $parts = [];
        foreach ([2,4,8,20,32,8,4,20,32,2,8,64] as $n) { $parts[] = A::from(str_repeat('5A', $n), 'hex'); }
        $tx = A::concat($parts);
        $h = $tx->toString('hex');
        $pre = $tx->slice(0, 32)->toString('hex');
        return [$h, $pre];
    },
    function () {
        $parts = [];
        foreach ([2,4,8,20,32,8,4,20,32,2,8,64] as $n) { $parts[] = B::from(str_repeat('5A', $n), 'hex'); }
        $tx = B::concat($parts);
        $h = $tx->toString('hex');
        $pre = $tx->slice(0, 32)->toString('hex');
        return [$h, $pre];
    });

printf("PHP %s | JIT=%s\n\n", PHP_VERSION, function_exists('opcache_get_status') && @opcache_get_status()['jit']['enabled'] ? 'on' : 'off');
printf("%-32s %9s %11s %11s %9s\n", 'operation', 'iters', 'SplFixed ms', 'string ms', 'speedup');
printf("%s\n", str_repeat('-', 76));
foreach ($rows as [$n, $it, $o, $nw, $sp]) {
    printf("%-32s %9d %11.1f %11.1f %8.2fx\n", $n, $it, $o, $nw, $sp);
}

// --- memory ---
echo "\nmemory for 1000 buffers of 32 bytes:\n";
$m0 = memory_get_usage(); $keep = []; for ($i=0;$i<1000;$i++) { $keep[] = A::from($hex32,'hex'); } $mA = memory_get_usage() - $m0;
unset($keep); gc_collect_cycles();
$m0 = memory_get_usage(); $keep = []; for ($i=0;$i<1000;$i++) { $keep[] = B::from($hex32,'hex'); } $mB = memory_get_usage() - $m0;
printf("  SplFixedArray %8.1f KB (%5.1f B/buffer)\n  string        %8.1f KB (%5.1f B/buffer)\n  factor        %8.2fx\n",
    $mA/1024, $mA/1000, $mB/1024, $mB/1000, $mA/max($mB,1));
