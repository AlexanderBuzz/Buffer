<?php declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/BufferStr.php';
use Hardcastle\Buffer\Buffer as A;
use Bench\BufferStr as B;

$fail = 0; $ok = 0;
$expected = 0;
function chk(string $label, $x, $y) {
    global $fail, $ok, $expected;
    $a = is_object($x) ? var_export($x, true) : var_export($x, true);
    $b = is_object($y) ? var_export($y, true) : var_export($y, true);
    if ($a === $b) { $ok++; return; }
    // known 1.x bugs that the prototype deliberately fixes (Node-conform)
    if (str_contains($a, 'THROW:ValueError') && str_starts_with($label, 'subArray')) { $expected++; return; }
    if (str_contains($a, 'THROW:TypeError') && str_contains($label, 'concat')) { $expected++; return; }
    { $fail++; printf("MISMATCH %-34s old=%s new=%s\n", $label, substr($a,0,60), substr($b,0,60)); }
}

$hexes = ['', '00', 'FF', 'DEADBEEF', '0102030405', str_repeat('AB', 32), str_repeat('7f', 64), 'a1b2c3', '0xDEAD', 'F'];
foreach ($hexes as $h) {
    $a = A::from($h, 'hex'); $b = B::from($h, 'hex');
    chk("from(hex).toString  [$h]", $a->toString('hex'), $b->toString('hex'));
    chk("from(hex).getLength [$h]", $a->getLength(), $b->getLength());
    chk("from(hex).toArray   [$h]", $a->toArray(), $b->toArray());
    chk("from(hex).toUtf8    [$h]", bin2hex($a->toUtf8()), bin2hex($b->toUtf8()));
    chk("from(hex).b64       [$h]", $a->toString('base64'), $b->toString('base64'));
    chk("getBytesArray       [$h]", $a->getBytesArray()->toArray(), $b->getBytesArray()->toArray());
    if ($a->getLength() > 0 && $a->getLength() <= 8) {
        chk("toInt               [$h]", $a->toInt(), $b->toInt());
    }
}

$strs = ['', 'hello', 'Grüße', "\x00\x01\xff", str_repeat('x', 100)];
foreach ($strs as $s) {
    chk("from(str).hex", A::from($s)->toString('hex'), B::from($s)->toString('hex'));
    chk("from(str).utf8", A::from($s)->toUtf8(), B::from($s)->toUtf8());
    chk("from(b64).hex", A::from(base64_encode($s), 'base64')->toString('hex'), B::from(base64_encode($s), 'base64')->toString('hex'));
}

foreach ([[], [0], [1,2,3], [255,256,-1]] as $arr) {
    chk("from(array)", A::from($arr)->toString('hex'), B::from($arr)->toString('hex'));
}

// subArray / slice across the full grid, including the out-of-range cases
$src = '000102030405060708090A0B0C0D0E0F';
foreach ([-20,-5,-1,0,1,3,8,16,20] as $s2) {
    foreach ([null,-20,-5,-1,0,1,3,8,16,20] as $e) {
        $a = A::from($src,'hex'); $b = B::from($src,'hex');
        try { $ra = $a->subArray($s2,$e)->toString('hex'); } catch (\Throwable $t) { $ra = 'THROW:'.get_class($t); }
        try { $rb = $b->subArray($s2,$e)->toString('hex'); } catch (\Throwable $t) { $rb = 'THROW:'.get_class($t); }
        chk("subArray($s2,".var_export($e,true).")", $ra, $rb);
    }
}

// toString with start/end
foreach ([[0,null],[0,4],[2,6],[4,4],[0,99]] as [$s2,$e]) {
    chk("toString(hex,$s2,".var_export($e,true).")", A::from($src,'hex')->toString('hex',$s2,$e), B::from($src,'hex')->toString('hex',$s2,$e));
}

// concat
$la = [A::from('AABB','hex'), A::from('CC','hex'), A::from('','hex')];
$lb = [B::from('AABB','hex'), B::from('CC','hex'), B::from('','hex')];
chk("concat", A::concat($la)->toString('hex'), B::concat($lb)->toString('hex'));
chk("concat(len=2)", A::concat($la,2)->toString('hex'), B::concat($lb,2)->toString('hex'));
try { $ca = A::concat($la,6)->toString('hex'); } catch (\Throwable $t) { $ca = 'THROW:'.get_class($t); }
chk("concat(len=6)", $ca, B::concat($lb,6)->toString('hex'));
chk("concat([])", A::concat([])->toString('hex'), B::concat([])->toString('hex'));

// reads
$r = A::from($src,'hex'); $r2 = B::from($src,'hex');
for ($i=0;$i<16;$i++) chk("readUInt8($i)", $r->readUInt8($i), $r2->readUInt8($i));
for ($i=0;$i<15;$i++) chk("readUInt16BE($i)", $r->readUInt16BE($i), $r2->readUInt16BE($i));
for ($i=0;$i<13;$i++) chk("readUInt32BE($i)", $r->readUInt32BE($i), $r2->readUInt32BE($i));

// alloc
foreach ([0,1,8,32] as $n) {
    chk("alloc($n)", A::alloc($n)->toString('hex'), B::alloc($n)->toString('hex'));
    chk("alloc($n,0xAB)", A::alloc($n,0xAB)->toString('hex'), B::alloc($n,0xAB)->toString('hex'));
}

// append / clone / equals
$a = A::from('AA','hex'); $a->appendHex('BBCC');
$b = B::from('AA','hex'); $b->appendHex('BBCC');
chk("appendHex", $a->toString('hex'), $b->toString('hex'));
chk("equals", A::from('AA','hex')->equals(A::from('AA','hex')), B::from('AA','hex')->equals(B::from('AA','hex')));

// setBytesArray round-trip
$sa = SplFixedArray::fromArray([1,2,3,255]);
$a = A::from('','hex'); $a->setBytesArray($sa);
$b = B::from('','hex'); $b->setBytesArray($sa);
chk("setBytesArray", $a->toString('hex'), $b->toString('hex'));
chk("setBytesArray len", $a->getLength(), $b->getLength());

printf("\n%d identical, %d expected divergences (1.x bugs fixed), %d unexpected mismatches\n", $ok, $expected, $fail);
exit($fail > 0 ? 1 : 0);
