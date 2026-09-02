# Upgrading

## 1.x → 2.0

One breaking change, plus a set of behaviour fixes that bring the library in
line with Node's `Buffer`. For most code the upgrade is a version bump.

### Breaking: `$length` is no longer a public property

`Buffer::$length` was public and writable, which meant the declared length
could be moved out of sync with the actual byte storage from outside the class:

```php
$buf = Buffer::from('0102030405', 'hex');
$buf->length = 2;          // 1.x: buffer now lies about its own size
$buf->toString('hex');     // '0102' -- the other three bytes are hidden
```

It is now private. Read it through `getLength()`, which has existed since 1.0
and needs no other change:

```php
- $buf->length
+ $buf->getLength()
```

Nothing else in the public API changed. `getBytesArray()` and
`setBytesArray()` keep their `SplFixedArray` signatures.

### Behaviour fixes

These are not API changes, but code that relied on the old, broken behaviour
will notice. Each one moves toward what Node does.

| | 1.x | 2.0 |
|---|---|---|
| `subArray()`/`slice()` starting past the end | `ValueError` from `SplFixedArray` | empty buffer |
| `$buf[$i] = $x` past the end | reallocated and grew the buffer | silent no-op |
| `unset($buf[$i])` | wrote `null` into the storage | no-op |
| `toInt()` on more than 8 bytes | silently returned `PHP_INT_MAX` | throws `OverflowException` |
| `concat($list, $totalLength)` with surplus length | left `null` holes | zero-fills |
| out-of-range `read*()`/`write*()` | undefined, or a leaked `SplFixedArray` exception | `OutOfBoundsException` |

Buffers are fixed size, as in Node. To grow one, use `set()`,
`appendBuffer()`/`appendHex()` or `prependBuffer()`/`prependHex()`.

If you were using `toInt()` on wide values, switch to `toDecimalString()` or,
for 64-bit quantities, the new `readBigUInt64BE()`/`readBigInt64BE()` family.

### Exceptions

Exceptions now live under `Hardcastle\Buffer\Exception` and share a
`BufferException` base:

```
BufferException            extends \RuntimeException
├── OutOfBoundsException   offset or range outside the buffer
├── OverflowException      value does not fit the target type
└── InvalidArgumentException  unsupported argument type
```

`BufferException` extends `RuntimeException`, so existing
`catch (\Exception $e)` blocks keep working unchanged. Code matching on the
old message string `'Requested Buffer element out of bounds'` needs updating:
the message now names the operation, offset, required size and actual length.
