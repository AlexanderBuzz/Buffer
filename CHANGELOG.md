# Changelog

All notable changes to this project are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [2.0.0] - 2026-09-02

Hardening release ahead of the next `xrpl-php`. One breaking change, six
behaviour fixes, a faster internal representation, and the API gaps against
Node closed. See [UPGRADING.md](UPGRADING.md) for the migration, which is a
one-line change for most code.

### Changed — breaking

- `Buffer::$length` is now **private**. It was public and writable, which let
  the declared length drift out of sync with the actual byte storage from
  outside the class. Use `getLength()`, which has existed since 1.0.

### Fixed

Each of these moves the library toward Node's behaviour. Code relying on the
old, broken behaviour will notice.

- `subArray()`/`slice()` starting past the end produced a negative length and
  crashed inside `SplFixedArray`. It now returns an empty buffer, as Node does.
- `$buf[$i] = $x` past the end reallocated and grew the buffer, so a mistyped
  index silently changed its length. Buffers are fixed size in Node, so this is
  now a no-op.
- `unset($buf[$i])` wrote `null` into the storage, which only surfaced later as
  a `TypeError` in an unrelated call. It is now a no-op.
- `toInt()` silently saturated at `PHP_INT_MAX` beyond 8 bytes. It now throws
  `OverflowException` and points at `toDecimalString()`.
- `concat($list, $totalLength)` with a total length larger than its inputs left
  the surplus slots as `null`. It now zero-fills.
- Out-of-range `read*()`/`write*()` reported themselves three different ways, or
  not at all. Every accessor now checks explicitly and throws one documented
  exception naming the operation, offset, required size and actual length.

### Added

- Variable-width accessors, 1 to 6 bytes: `readUIntBE/LE`, `readIntBE/LE`,
  `writeUIntBE/LE`, `writeIntBE/LE`.
- 64-bit accessors: `readBigUInt64BE/LE`, `readBigInt64BE/LE` and the matching
  writes. These return and accept `Brick\Math\BigInteger`, because PHP's signed
  `int` cannot hold the unsigned 64-bit range at all.
- `toJSON()`, matching Node's `{"type":"Buffer","data":[...]}` serialisation.
- `keys()`, `values()`, `entries()` as generators.
- `transcode()`, delegating to `ext-mbstring` (declared as a suggestion, not a
  requirement).
- An exception hierarchy under `Hardcastle\Buffer\Exception`:
  `BufferException` (extending `RuntimeException`, so existing
  `catch (\Exception)` blocks keep working), with `OutOfBoundsException`,
  `OverflowException` and `InvalidArgumentException`.

### Performance

Bytes are now held in a PHP string rather than an `SplFixedArray` of integers,
where every byte costs a full zval. Measured against 1.x:

| | 1.x | 2.0 |
|---|---:|---:|
| 12-field transaction serialise | 701.8 ms | 112.0 ms (**6.3x**) |
| `toUtf8` (256 B) | 416.5 ms | 1.7 ms (**247x**) |
| `toString('hex')` (256 B) | 657.8 ms | 16.6 ms (**39.6x**) |
| `from(hex, 32 B)` | 361.1 ms | 76.5 ms (**4.7x**) |
| `concat` | 166.3 ms | 26.8 ms (**6.2x**) |
| memory, 1000 × 32-byte buffers | 699.9 KB | 160.7 KB (**4.4x**) |

Two accessors regress, both known and measured: `toArray()` at 0.23x, because
`SplFixedArray` already holds an int array while a string must materialise a
zval per byte, and `readUInt8()` at 0.95x. Neither shows through at the
workload level — even a case built deliberately to favour the old
representation comes out 1.38x ahead.

### Documentation

The README now states the deliberate deviations from Node rather than leaving
them to be discovered: `slice()` copies where Node returns a view,
`toString()` defaults to `'hex'` where Node defaults to `'utf8'`, `toUtf8()`
returns raw bytes without decoding, and a negative `byteOffset` in
`indexOf()`/`lastIndexOf()` counts from zero rather than from the end.

### Internal

- Test suite raised from 14 tests / 49 assertions to 316 / 606.
- Psalm 5.26 → 6.16.1, clean and without deprecation noise on PHP 8.4.
- Fixed two CI workflows that referenced undefined environment variables. The
  PHPUnit step was gated on `env.COVERAGE_PHP_VERSION`, which was never
  defined, so the comparison was against the empty string and the test suite
  had never actually run in CI.

[2.0.0]: https://github.com/AlexanderBuzz/Buffer/releases/tag/2.0.0
