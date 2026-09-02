# Buffer
PHP implementation of the Node.js Buffer module, available for PHP 8.2+.

## Features
- Mirrors the Node.js Buffer API. Deviations are listed below, deliberately and in full.
- Supports `utf8`, `hex` and `base64` encodings.
- Bytes are held in a PHP string, which is already a byte array and roughly four times more compact than one zval per byte.
- Reading and writing:
  - Fixed-width integers: `Int8`, `UInt8`, `Int16BE/LE`, `UInt16BE/LE`, `Int32BE/LE`, `UInt32BE/LE`.
  - Variable width, 1 to 6 bytes: `UIntBE/LE`, `IntBE/LE`.
  - 64-bit: `BigInt64BE/LE`, `BigUInt64BE/LE`, via `brick/math` `BigInteger`.
  - Floats and doubles: `FloatBE/LE`, `DoubleBE/LE`.
- Utility methods: `fill`, `write`, `copy`, `compare`, `indexOf`, `lastIndexOf`, `includes`, `swap16/32/64`, `toJSON`, `keys`, `values`, `entries`, `transcode`.
- `ArrayAccess` for byte-level access.

## Installation
```bash
composer require hardcastle/buffer
```

Upgrading from 1.x? See [UPGRADING.md](UPGRADING.md). One line changes for most code.

## Usage
### Create Buffer
```php
use Hardcastle\Buffer\Buffer;

// From size
$buf = Buffer::alloc(10);

// From array
$buf = Buffer::from([0x62, 0x75, 0x66, 0x66, 0x65, 0x72]);

// From string
$buf = Buffer::from('hello world', 'utf8');

// From hex
$buf = Buffer::from('627566666572', 'hex');

// From base64
$buf = Buffer::from('aGVsbG8=', 'base64');
```

### Access & Modification
```php
$buf = Buffer::alloc(10);
$buf[0] = 0x41; // 'A'
echo $buf[0]; // 65
echo $buf->getLength(); // 10

$buf->write('hello', 1);
echo $buf->toUtf8(); // Ahello
```

### Reading & Writing
```php
$buf = Buffer::alloc(4);
$buf->writeInt32BE(0x12345678, 0);
echo dechex($buf->readInt32BE(0)); // 12345678
```

### 64-bit values
PHP's `int` is signed, so unsigned 64-bit quantities do not fit in one. The
`Big*` family returns and accepts `Brick\Math\BigInteger`:

```php
$buf = Buffer::from('FFFFFFFFFFFFFFFF', 'hex');
echo $buf->readBigUInt64BE();   // 18446744073709551615

$out = Buffer::alloc(8);
$out->writeBigUInt64BE('100000000');
```

`toInt()` is limited to 8 bytes and throws `OverflowException` beyond that,
rather than silently returning `PHP_INT_MAX`. Use `toDecimalString()` or the
`Big*` family for wider values.

## Differences from Node.js

Three deliberate deviations. They are safe, but they are the kind of thing that
bites when porting code across, so they are spelled out rather than left to be
discovered.

**`slice()` and `subArray()` copy; in Node they are views.**
In Node, a slice shares memory with its parent, and mutating one is visible in
the other. Here you get an independent copy:

```php
$original = Buffer::from('DEADBEEF', 'hex');
$slice = $original->slice(0, 2);
$slice->writeUInt8(0x00, 0);

echo $original->toString('hex'); // DEADBEEF -- unchanged
```

Copying is the safer default and the behaviour existing consumers rely on, so
it stays. If you need Node's aliasing, keep the parent and work with offsets.

**`toString()` defaults to `'hex'`; Node defaults to `'utf8'`.**
Convenient for binary protocol work, and the reason it is this way, but it means
`$buf->toString()` returns something different than it would in Node. Pass the
encoding explicitly if it matters.

**`toUtf8()` does not decode UTF-8; it returns raw bytes.**
It is effectively `toBinaryString()`: one `chr()` per byte, no validation. Node
replaces invalid sequences with U+FFFD. For well-formed UTF-8 the two agree; for
arbitrary binary they do not. Use it to get at the bytes, not to validate them.

A fourth, smaller one: `indexOf()`/`lastIndexOf()` treat a negative
`byteOffset` as position zero, whereas Node counts it from the end of the
buffer.

## Errors

All exceptions extend `Hardcastle\Buffer\Exception\BufferException`, which
extends `RuntimeException`:

- `OutOfBoundsException` — an offset or range outside the buffer. Node's `ERR_OUT_OF_RANGE`.
- `OverflowException` — a value that does not fit the target type, such as `toInt()` past 8 bytes.
- `InvalidArgumentException` — an unsupported argument type or shape.

Buffers are fixed size, as in Node: `$buf[$i] = $x` past the end is a silent
no-op, and `unset($buf[$i])` does nothing. Use `set()`, `appendBuffer()` or
`prependBuffer()` to grow a buffer.

## License
MIT
