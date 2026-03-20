# Buffer
PHP implementation of the Node.js Buffer module, available for PHP 8.2+.

## Features
- Fully compliant with Node.js Buffer API.
- Supports various encodings: `utf8`, `hex`, `base64`.
- Memory-efficient storage using `SplFixedArray`.
- Supports reading and writing various data types:
  - Integers: `Int8`, `UInt8`, `Int16BE/LE`, `UInt16BE/LE`, `Int32BE/LE`, `UInt32BE/LE`.
  - Floats & Doubles: `FloatBE/LE`, `DoubleBE/LE`.
- Utility methods: `fill`, `write`, `copy`, `compare`, `indexOf`, `lastIndexOf`, `includes`, `swap16/32/64`.
- `ArrayAccess` implementation for easy byte access.

## Installation
```bash
composer require hardcastle/buffer
```

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
echo $buf->length; // 10

$buf->write('hello', 1);
echo $buf->toUtf8(); // Ahello
```

### Reading & Writing
```php
$buf = Buffer::alloc(4);
$buf->writeInt32BE(0x12345678, 0);
echo dechex($buf->readInt32BE(0)); // 12345678
```

## License
MIT
