<?php declare(strict_types=1);
/**
 * PHP Buffer
 *
 * Copyright (c) Alexander Busse | Hardcastle Technologies
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Hardcastle\Buffer;

use ArrayAccess;
use Brick\Math\BigInteger;
use Exception;
use Generator;
use Hardcastle\Buffer\Exception\BufferException;
use Hardcastle\Buffer\Exception\InvalidArgumentException;
use Hardcastle\Buffer\Exception\OutOfBoundsException;
use Hardcastle\Buffer\Exception\OverflowException;
use SplFixedArray;

/**
 * Implements the functionality of Node.js Buffer (https://nodejs.org/api/buffer.html).
 * @template-implements ArrayAccess<int, int>
 */
class Buffer implements ArrayAccess
{
    public const DEFAULT_FILL = 0x00;

    /**
     * Guards an accessor against reading or writing outside the buffer.
     *
     * SplFixedArray would throw its own generic exception here, and a string
     * backed buffer would not throw at all, so the check is explicit and the
     * exception uniform across every accessor.
     *
     * @param string $operation
     * @param int $offset
     * @param int $size
     * @return void
     * @throws OutOfBoundsException
     */
    private function assertRange(string $operation, int $offset, int $size): void
    {
        if ($offset < 0 || $offset + $size > $this->length) {
            throw OutOfBoundsException::forRange($operation, $offset, $size, $this->length);
        }
    }

    /**
     * Byte length of the buffer.
     *
     * Private since 2.0: it used to be public and writable, which allowed the
     * declared length to be moved out of sync with the actual byte storage from
     * outside the class. Read it through getLength().
     *
     * @var int
     */
    private int $length;

    /**
     * The bytes themselves.
     *
     * A PHP string is already a byte array, and a far more compact one than
     * SplFixedArray, where every byte costs a full zval. Kept private, with
     * getBytesArray()/setBytesArray() bridging to SplFixedArray for callers
     * that still expect it.
     *
     * @var string
     */
    private string $bytes;

    /**
     * Buffer constructor.
     *
     * @param int $length
     */
    public function __construct(int $length = 0)
    {
        $this->length = max(0, $length);
        $this->bytes = str_repeat(chr(self::DEFAULT_FILL), $this->length);
    }

    /**
     * Wraps a raw byte string without copying or converting it.
     *
     * @param string $raw
     * @return self
     */
    private static function wrap(string $raw): self
    {
        $buffer = new self(0);
        $buffer->bytes = $raw;
        $buffer->length = strlen($raw);

        return $buffer;
    }

    /**
     * Creates a new buffer of given size
     *
     * @param int $size
     * @param int|string|Buffer $fill
     * @param string $encoding
     * @return Buffer
     */
    public static function alloc(int $size = 0, int|string|Buffer $fill = self::DEFAULT_FILL, string $encoding = 'utf-8'): Buffer
    {
        $buffer = new self($size);
        if ($size > 0 && $fill !== self::DEFAULT_FILL) {
            $buffer->fill($fill, 0, $size, $encoding);
        }

        return $buffer;
    }

    /**
     * Creates a new Buffer from different sources
     *
     * @param mixed $source
     * @param string|null $encoding
     * @return Buffer
     * @throws Exception
     */
    public static function from(mixed $source, ?string $encoding = 'utf-8'): Buffer
    {
        // Duplicate buffer
        if ($source instanceof Buffer) {
            return self::wrap($source->bytes);
        }

        // Buffer from byte array [12, 108, 0, 230]
        if (is_array($source)) {
            $raw = '';
            foreach ($source as $byte) {
                $raw .= chr((int)$byte & 0xFF);
            }
            return self::wrap($raw);
        }

        // Buffer from Bricks/BigInteger
        if ($source instanceof BigInteger) {
            return self::from($source->toBase(16), 'hex');
        }

        if (is_string($source)) {
            if ($encoding === 'hex') {
                $source = preg_replace('/[^a-fA-F0-9xX]/', '', $source);
                if (str_starts_with($source, '0x') || str_starts_with($source, '0X')) {
                    $source = substr($source, 2);
                }
                if (strlen($source) % 2) {
                    $source = '0' . $source;
                }
                return self::wrap($source === '' ? '' : (string)hex2bin($source));
            }

            if ($encoding === 'base64') {
                $source = base64_decode($source);
                // After decoding base64, we treat it as a raw string
            }

            // Treat as raw binary string/UTF-8
            return self::wrap($source);
        }

        throw new InvalidArgumentException('Buffer does not support source type: ' . gettype($source));
    }

    /**
     * Creates a single buffer from an array of Buffers by concatenating them
     *
     * @param array $bufferList
     * @param int|null $totalLength
     * @return Buffer
     * @throws Exception
     */
    public static function concat(array $bufferList, ?int $totalLength = null): Buffer
    {
        if (empty($bufferList)) {
            return new self(0);
        }

        if ($totalLength === null) {
            $totalLength = 0;
            foreach ($bufferList as $buffer) {
                if ($buffer instanceof Buffer) {
                    $totalLength += $buffer->length;
                }
            }
        }

        $raw = '';
        foreach ($bufferList as $buffer) {
            if ($buffer instanceof Buffer) {
                if (strlen($raw) >= $totalLength) {
                    break;
                }
                $raw .= $buffer->bytes;
            }
        }

        // Truncate an overlong result, zero-fill a short one, as Node does.
        if (strlen($raw) > $totalLength) {
            $raw = substr($raw, 0, $totalLength);
        } elseif (strlen($raw) < $totalLength) {
            $raw = str_pad($raw, $totalLength, chr(self::DEFAULT_FILL));
        }

        return self::wrap($raw);
    }

    /**
     * Returns the byte length of a string.
     *
     * @param string $string
     * @param string $encoding
     * @return int
     */
    public static function byteLength(string $string, string $encoding = 'utf8'): int
    {
        if ($encoding === 'hex') {
            return (int)(strlen($string) / 2);
        }

        if ($encoding === 'base64') {
            return strlen(base64_decode($string));
        }

        return strlen($string);
    }

    /**
     * Compares buf1 with buf2, usually used for sorting arrays of Buffer instances.
     *
     * @param Buffer $buf1
     * @param Buffer $buf2
     * @return int
     */
    public static function compare(Buffer $buf1, Buffer $buf2): int
    {
        return $buf1->compareTo($buf2);
    }

    /**
     * Returns true if obj is a Buffer.
     *
     * @param mixed $obj
     * @return bool
     */
    public static function isBuffer(mixed $obj): bool
    {
        return $obj instanceof Buffer;
    }

    /**
     * Returns true if encoding is the name of a supported character encoding.
     *
     * @param string $encoding
     * @return bool
     */
    public static function isEncoding(string $encoding): bool
    {
        return in_array(strtolower($encoding), ['utf8', 'utf-8', 'hex', 'base64', 'ascii', 'latin1', 'binary']);
    }

    /**
     * Creates a random bytes filled Buffer of given size
     *
     * @param int $size
     * @return Buffer
     * @throws Exception
     */
    public static function random(int $size): Buffer
    {
        if ($size < 1) {
            return self::alloc();
        }

        return self::from(random_bytes($size));
    }
    
    /**
     * Creates a new Buffer that is a clone of the current Buffer.
     *
     * @return Buffer
     * @throws Exception
     */
    public function clone(): Buffer
    {
        return self::from($this);
    }

    /**
     * @return int
     */
    public function getLength(): int
    {
        return $this->length;
    }

    /**
     * Fills the buffer with the specified value.
     *
     * @param int|string|Buffer $value
     * @param int $offset
     * @param int|null $end
     * @param string $encoding
     * @return $this
     */
    public function fill(int|string|Buffer $value, int $offset = 0, ?int $end = null, string $encoding = 'utf-8'): self
    {
        $end ??= $this->length;

        if ($offset < 0 || $offset >= $this->length || $end < $offset || $end > $this->length) {
            return $this;
        }

        $span = $end - $offset;
        if ($span <= 0) {
            return $this;
        }

        if (is_int($value)) {
            $pattern = chr($value & 0xFF);
        } else {
            $fillBuf = $value instanceof self ? $value : self::from($value, $encoding);
            $pattern = $fillBuf->length === 0 ? chr(self::DEFAULT_FILL) : $fillBuf->bytes;
        }

        $filled = substr(str_repeat($pattern, intdiv($span, strlen($pattern)) + 1), 0, $span);
        $this->bytes = substr_replace($this->bytes, $filled, $offset, $span);

        return $this;
    }

    /**
     * Writes string to buf at offset according to the character encoding in encoding.
     *
     * @param string $string
     * @param int $offset
     * @param int|null $length
     * @param string $encoding
     * @return int
     */
    public function write(string $string, int $offset = 0, ?int $length = null, string $encoding = 'utf-8'): int
    {
        $writeBuf = self::from($string, $encoding);
        $length = $length === null ? $writeBuf->length : min($length, $writeBuf->length);
        $length = min($length, $this->length - $offset);

        if ($length <= 0 || $offset >= $this->length) {
            return 0;
        }

        $this->bytes = substr_replace($this->bytes, substr($writeBuf->bytes, 0, $length), $offset, $length);

        return $length;
    }

    /**
     * Copies data from a region of buf to a region in target, even if the target memory region overlaps with buf.
     *
     * @param Buffer $target
     * @param int $targetStart
     * @param int $sourceStart
     * @param int|null $sourceEnd
     * @return int
     */
    public function copy(Buffer $target, int $targetStart = 0, int $sourceStart = 0, ?int $sourceEnd = null): int
    {
        $sourceEnd ??= $this->length;

        if ($sourceStart >= $this->length || $targetStart >= $target->length) {
            return 0;
        }

        $copyLength = min($sourceEnd - $sourceStart, $this->length - $sourceStart, $target->length - $targetStart);
        if ($copyLength <= 0) {
            return 0;
        }

        // substr() snapshots the source, so overlapping regions are safe without
        // the backwards copy the element-wise version needed.
        $target->bytes = substr_replace(
            $target->bytes,
            substr($this->bytes, $sourceStart, $copyLength),
            $targetStart,
            $copyLength
        );

        return $copyLength;
    }

    /**
     * Compares buf with target and returns a number indicating whether buf comes before, after, or is the same as target in sort order.
     *
     * @param Buffer $target
     * @param int $targetStart
     * @param int|null $targetEnd
     * @param int $sourceStart
     * @param int|null $sourceEnd
     * @return int
     */
    public function compareTo(Buffer $target, int $targetStart = 0, ?int $targetEnd = null, int $sourceStart = 0, ?int $sourceEnd = null): int
    {
        $targetEnd ??= $target->length;
        $sourceEnd ??= $this->length;

        $sLen = $sourceEnd - $sourceStart;
        $tLen = $targetEnd - $targetStart;
        $len = min($sLen, $tLen);

        $comparison = strcmp(
            substr($this->bytes, $sourceStart, $len),
            substr($target->bytes, $targetStart, $len)
        );
        if ($comparison !== 0) {
            return $comparison < 0 ? -1 : 1;
        }

        if ($sLen !== $tLen) {
            return $sLen < $tLen ? -1 : 1;
        }

        return 0;
    }

    /**
     * Returns true if both buf and otherBuffer have exactly the same bytes.
     *
     * @param Buffer $otherBuffer
     * @return bool
     */
    public function equals(Buffer $otherBuffer): bool
    {
        return $this->compareTo($otherBuffer) === 0;
    }

    /**
     * Appends a buffer to the current one.
     *
     * @param Buffer $appendix
     * @return void
     */
    public function appendBuffer(Buffer $appendix): void
    {
        $this->bytes .= $appendix->bytes;
        $this->length += $appendix->length;
    }

    /**
     * Appends hex bytes to the current buffer.
     *
     * @param string $hexBytes
     * @return void
     * @throws Exception
     */
    public function appendHex(string $hexBytes): void
    {
        $this->appendBuffer(self::from($hexBytes, 'hex'));
    }

    /**
     * Prepends a buffer to the current one.
     *
     * @param Buffer $prefix
     * @return void
     */
    public function prependBuffer(Buffer $prefix): void
    {
        $this->bytes = $prefix->bytes . $this->bytes;
        $this->length += $prefix->length;
    }

    /**
     * Prepends hex bytes to the current buffer.
     *
     * @param string $hexBytes
     * @return void
     * @throws Exception
     */
    public function prependHex(string $hexBytes): void
    {
        $this->prependBuffer(self::from($hexBytes, 'hex'));
    }

    /**
     * Sets multiple bytes starting from the given index.
     *
     * @param int $startIdx
     * @param array $bytes
     * @return void
     */
    public function set(int $startIdx, array $bytes): void
    {
        $bytesLength = count($bytes);
        $newLength = max($this->length, $startIdx + $bytesLength);

        if ($newLength > $this->length) {
            // Zero-fill any gap between the old end and $startIdx.
            $this->bytes = str_pad($this->bytes, $newLength, chr(self::DEFAULT_FILL));
            $this->length = $newLength;
        }

        $raw = '';
        foreach ($bytes as $byte) {
            $raw .= chr((int)$byte & 0xFF);
        }

        $this->bytes = substr_replace($this->bytes, $raw, $startIdx, $bytesLength);
    }

    /**
     * Returns a new Buffer that references the same memory as the original, but offset and cropped by the start and end indices.
     *
     * @param int $start
     * @param int|null $end
     * @return Buffer
     */
    public function subArray(int $start = 0, ?int $end = null): Buffer
    {
        $end ??= $this->length;

        if ($start < 0) {
            $start = max(0, $this->length + $start);
        }
        if ($end < 0) {
            $end = max(0, $this->length + $end);
        }

        $start = min($start, $this->length);
        $length = max(0, min($end - $start, $this->length - $start));

        return self::wrap(substr($this->bytes, $start, $length));
    }

    /**
     * Alias for subArray
     *
     * @param int $start
     * @param int|null $end
     * @return Buffer
     */
    public function slice(int $start = 0, ?int $end = null): Buffer
    {
        return $this->subArray($start, $end);
    }

    /**
     * Decodes buf to a string according to the specified character encoding.
     *
     * @param string $encoding
     * @param int $start
     * @param int|null $end
     * @return string
     */
    public function toString(string $encoding = 'hex', int $start = 0, ?int $end = null): string
    {
        $end ??= $this->length;
        $raw = ($start === 0 && $end === $this->length)
            ? $this->bytes
            : $this->subArray($start, $end)->bytes;

        if ($encoding === 'hex') {
            return strtoupper(bin2hex($raw));
        }

        if ($encoding === 'base64') {
            return base64_encode($raw);
        }

        return $raw;
    }

    /**
     * Returns the content as byte array.
     *
     * @return list<int>
     */
    public function toArray(): array
    {
        if ($this->length === 0) {
            return [];
        }

        /** @var list<int> $bytes */
        $bytes = array_values(unpack('C*', $this->bytes));

        return $bytes;
    }

    /**
     * Returns the buffer content as an integer.
     *
     * Limited to 8 bytes, because anything wider cannot be represented by a PHP
     * int. Use toDecimalString() or readBigUInt64BE() for larger values.
     *
     * @return int
     * @throws OverflowException If the buffer is longer than 8 bytes.
     */
    public function toInt(): int
    {
        if ($this->length > 8) {
            throw new OverflowException(sprintf(
                'Cannot convert a %d byte buffer to int without loss; the limit is 8 bytes. '
                . 'Use toDecimalString() or readBigUInt64BE() instead.',
                $this->length
            ));
        }

        if ($this->length === 0) {
            return 0;
        }

        return (int)BigInteger::fromBase($this->toString('hex'), 16)->toBase(10);
    }

    /**
     * Returns the big integer as decimal string.
     *
     * @return string
     */
    public function toDecimalString(): string
    {
        return BigInteger::fromBase($this->toString('hex'), 16)->toBase(10);
    }

    /**
     * Decodes buf to a UTF-8 string.
     *
     * @return string
     */
    public function toUtf8(): string
    {
        return $this->bytes;
    }

    /**
     * Swap the byte order of a 16-bit Buffer.
     *
     * @return $this
     * @throws Exception
     */
    public function swap16(): self
    {
        if ($this->length % 2 !== 0) {
            throw new Exception('Buffer size must be a multiple of 16-bits');
        }

        $this->bytes = (string)preg_replace_callback(
            '/../s',
            static fn (array $m): string => strrev($m[0]),
            $this->bytes
        );

        return $this;
    }

    /**
     * Swap the byte order of a 32-bit Buffer.
     *
     * @return $this
     * @throws Exception
     */
    public function swap32(): self
    {
        if ($this->length % 4 !== 0) {
            throw new Exception('Buffer size must be a multiple of 32-bits');
        }

        $this->bytes = (string)preg_replace_callback(
            '/..../s',
            static fn (array $m): string => strrev($m[0]),
            $this->bytes
        );

        return $this;
    }

    /**
     * Swap the byte order of a 64-bit Buffer.
     *
     * @return $this
     * @throws Exception
     */
    public function swap64(): self
    {
        if ($this->length % 8 !== 0) {
            throw new Exception('Buffer size must be a multiple of 64-bits');
        }

        $this->bytes = (string)preg_replace_callback(
            '/......../s',
            static fn (array $m): string => strrev($m[0]),
            $this->bytes
        );

        return $this;
    }

    /**
     * Returns the first index at which value can be found in buf, or -1 if buf does not contain value.
     *
     * @param string|int|Buffer $value
     * @param int $byteOffset
     * @param string $encoding
     * @return int
     */
    public function indexOf(string|int|Buffer $value, int $byteOffset = 0, string $encoding = 'utf-8'): int
    {
        $valBuf = $value instanceof Buffer ? $value : (is_int($value) ? self::from([$value]) : self::from($value, $encoding));
        if ($valBuf->length === 0) {
            return -1;
        }

        if ($byteOffset > $this->length - $valBuf->length) {
            return -1;
        }

        $position = strpos($this->bytes, $valBuf->bytes, max(0, $byteOffset));

        return $position === false ? -1 : $position;
    }

    /**
     * Equivalent to buf.indexOf() !== -1.
     *
     * @param string|int|Buffer $value
     * @param int $byteOffset
     * @param string $encoding
     * @return bool
     */
    public function includes(string|int|Buffer $value, int $byteOffset = 0, string $encoding = 'utf-8'): bool
    {
        return $this->indexOf($value, $byteOffset, $encoding) !== -1;
    }

    /**
     * Returns the last index at which value can be found in buf, or -1 if buf does not contain value.
     *
     * @param string|int|Buffer $value
     * @param int|null $byteOffset
     * @param string $encoding
     * @return int
     */
    public function lastIndexOf(string|int|Buffer $value, ?int $byteOffset = null, string $encoding = 'utf-8'): int
    {
        $valBuf = $value instanceof Buffer ? $value : (is_int($value) ? self::from([$value]) : self::from($value, $encoding));
        if ($valBuf->length === 0) {
            return -1;
        }

        $byteOffset ??= $this->length - $valBuf->length;
        $byteOffset = min($byteOffset, $this->length - $valBuf->length);

        if ($byteOffset < 0) {
            return -1;
        }

        // Search only the window that could still start at or before $byteOffset.
        $haystack = substr($this->bytes, 0, $byteOffset + $valBuf->length);
        $position = strrpos($haystack, $valBuf->bytes);

        return $position === false ? -1 : $position;
    }

    /**
     * Reads an 8-bit integer from buf at the specified offset.
     *
     * @param int $offset
     * @return int
     */
    public function readInt8(int $offset = 0): int
    {
        $this->assertRange('readInt8', $offset, 1);

        $val = ord($this->bytes[$offset]);
        return $val > 127 ? $val - 256 : $val;
    }

    /**
     * Reads an unsigned 8-bit integer from buf at the specified offset.
     *
     * @param int $offset
     * @return int
     */
    public function readUInt8(int $offset = 0): int
    {
        $this->assertRange('readUInt8', $offset, 1);

        return ord($this->bytes[$offset]);
    }

    /**
     * Reads a signed 16-bit integer (big-endian) from buf at the specified offset.
     *
     * @param int $offset
     * @return int
     */
    public function readInt16BE(int $offset = 0): int
    {
        $this->assertRange('readInt16BE', $offset, 2);

        $val = (ord($this->bytes[$offset]) << 8) | ord($this->bytes[$offset + 1]);
        return $val > 32767 ? $val - 65536 : $val;
    }

    /**
     * Reads a signed 16-bit integer (little-endian) from buf at the specified offset.
     *
     * @param int $offset
     * @return int
     */
    public function readInt16LE(int $offset = 0): int
    {
        $this->assertRange('readInt16LE', $offset, 2);

        $val = (ord($this->bytes[$offset + 1]) << 8) | ord($this->bytes[$offset]);
        return $val > 32767 ? $val - 65536 : $val;
    }

    /**
     * Reads an unsigned 16-bit integer (big-endian) from buf at the specified offset.
     *
     * @param int $offset
     * @return int
     */
    public function readUInt16BE(int $offset = 0): int
    {
        $this->assertRange('readUInt16BE', $offset, 2);

        return (ord($this->bytes[$offset]) << 8) | ord($this->bytes[$offset + 1]);
    }

    /**
     * Reads an unsigned 16-bit integer (little-endian) from buf at the specified offset.
     *
     * @param int $offset
     * @return int
     */
    public function readUInt16LE(int $offset = 0): int
    {
        $this->assertRange('readUInt16LE', $offset, 2);

        return (ord($this->bytes[$offset + 1]) << 8) | ord($this->bytes[$offset]);
    }

    /**
     * Reads a signed 32-bit integer (big-endian) from buf at the specified offset.
     *
     * @param int $offset
     * @return int
     */
    public function readInt32BE(int $offset = 0): int
    {
        $this->assertRange('readInt32BE', $offset, 4);

        $val = (ord($this->bytes[$offset]) << 24) | (ord($this->bytes[$offset + 1]) << 16) | (ord($this->bytes[$offset + 2]) << 8) | ord($this->bytes[$offset + 3]);
        return $val > 2147483647 ? $val - 4294967296 : $val;
    }

    /**
     * Reads a signed 32-bit integer (little-endian) from buf at the specified offset.
     *
     * @param int $offset
     * @return int
     */
    public function readInt32LE(int $offset = 0): int
    {
        $this->assertRange('readInt32LE', $offset, 4);

        $val = (ord($this->bytes[$offset + 3]) << 24) | (ord($this->bytes[$offset + 2]) << 16) | (ord($this->bytes[$offset + 1]) << 8) | ord($this->bytes[$offset]);
        return $val > 2147483647 ? $val - 4294967296 : $val;
    }

    /**
     * Reads an unsigned 32-bit integer (big-endian) from buf at the specified offset.
     *
     * @param int $offset
     * @return int
     */
    public function readUInt32BE(int $offset = 0): int
    {
        $this->assertRange('readUInt32BE', $offset, 4);

        return ((ord($this->bytes[$offset]) << 24) | (ord($this->bytes[$offset + 1]) << 16) | (ord($this->bytes[$offset + 2]) << 8) | ord($this->bytes[$offset + 3])) & 0xFFFFFFFF;
    }

    /**
     * Reads an unsigned 32-bit integer (little-endian) from buf at the specified offset.
     *
     * @param int $offset
     * @return int
     */
    public function readUInt32LE(int $offset = 0): int
    {
        $this->assertRange('readUInt32LE', $offset, 4);

        return ((ord($this->bytes[$offset + 3]) << 24) | (ord($this->bytes[$offset + 2]) << 16) | (ord($this->bytes[$offset + 1]) << 8) | ord($this->bytes[$offset])) & 0xFFFFFFFF;
    }

    /**
     * Writes value to buf at the specified offset as an 8-bit integer.
     *
     * @param int $value
     * @param int $offset
     * @return int
     */
    public function writeInt8(int $value, int $offset = 0): int
    {
        $this->assertRange('writeInt8', $offset, 1);

        $this->bytes[$offset] = chr($value & 0xFF);
        return $offset + 1;
    }

    /**
     * Writes value to buf at the specified offset as an unsigned 8-bit integer.
     *
     * @param int $value
     * @param int $offset
     * @return int
     */
    public function writeUInt8(int $value, int $offset = 0): int
    {
        $this->assertRange('writeUInt8', $offset, 1);

        $this->bytes[$offset] = chr($value & 0xFF);
        return $offset + 1;
    }

    /**
     * Writes value to buf at the specified offset as a signed 16-bit integer (big-endian).
     *
     * @param int $value
     * @param int $offset
     * @return int
     */
    public function writeInt16BE(int $value, int $offset = 0): int
    {
        $this->assertRange('writeInt16BE', $offset, 2);

        $this->bytes[$offset] = chr(($value >> 8) & 0xFF);
        $this->bytes[$offset + 1] = chr($value & 0xFF);
        return $offset + 2;
    }

    /**
     * Writes value to buf at the specified offset as a signed 16-bit integer (little-endian).
     *
     * @param int $value
     * @param int $offset
     * @return int
     */
    public function writeInt16LE(int $value, int $offset = 0): int
    {
        $this->assertRange('writeInt16LE', $offset, 2);

        $this->bytes[$offset] = chr($value & 0xFF);
        $this->bytes[$offset + 1] = chr(($value >> 8) & 0xFF);
        return $offset + 2;
    }

    /**
     * Writes value to buf at the specified offset as an unsigned 16-bit integer (big-endian).
     *
     * @param int $value
     * @param int $offset
     * @return int
     */
    public function writeUInt16BE(int $value, int $offset = 0): int
    {
        $this->assertRange('writeUInt16BE', $offset, 2);

        $this->bytes[$offset] = chr(($value >> 8) & 0xFF);
        $this->bytes[$offset + 1] = chr($value & 0xFF);
        return $offset + 2;
    }

    /**
     * Writes value to buf at the specified offset as an unsigned 16-bit integer (little-endian).
     *
     * @param int $value
     * @param int $offset
     * @return int
     */
    public function writeUInt16LE(int $value, int $offset = 0): int
    {
        $this->assertRange('writeUInt16LE', $offset, 2);

        $this->bytes[$offset] = chr($value & 0xFF);
        $this->bytes[$offset + 1] = chr(($value >> 8) & 0xFF);
        return $offset + 2;
    }

    /**
     * Writes value to buf at the specified offset as a signed 32-bit integer (big-endian).
     *
     * @param int $value
     * @param int $offset
     * @return int
     */
    public function writeInt32BE(int $value, int $offset = 0): int
    {
        $this->assertRange('writeInt32BE', $offset, 4);

        $this->bytes[$offset] = chr(($value >> 24) & 0xFF);
        $this->bytes[$offset + 1] = chr(($value >> 16) & 0xFF);
        $this->bytes[$offset + 2] = chr(($value >> 8) & 0xFF);
        $this->bytes[$offset + 3] = chr($value & 0xFF);
        return $offset + 4;
    }

    /**
     * Writes value to buf at the specified offset as a signed 32-bit integer (little-endian).
     *
     * @param int $value
     * @param int $offset
     * @return int
     */
    public function writeInt32LE(int $value, int $offset = 0): int
    {
        $this->assertRange('writeInt32LE', $offset, 4);

        $this->bytes[$offset] = chr($value & 0xFF);
        $this->bytes[$offset + 1] = chr(($value >> 8) & 0xFF);
        $this->bytes[$offset + 2] = chr(($value >> 16) & 0xFF);
        $this->bytes[$offset + 3] = chr(($value >> 24) & 0xFF);
        return $offset + 4;
    }

    /**
     * Writes value to buf at the specified offset as an unsigned 32-bit integer (big-endian).
     *
     * @param int $value
     * @param int $offset
     * @return int
     */
    public function writeUInt32BE(int $value, int $offset = 0): int
    {
        $this->assertRange('writeUInt32BE', $offset, 4);

        $this->bytes[$offset] = chr(($value >> 24) & 0xFF);
        $this->bytes[$offset + 1] = chr(($value >> 16) & 0xFF);
        $this->bytes[$offset + 2] = chr(($value >> 8) & 0xFF);
        $this->bytes[$offset + 3] = chr($value & 0xFF);
        return $offset + 4;
    }

    /**
     * Writes value to buf at the specified offset as an unsigned 32-bit integer (little-endian).
     *
     * @param int $value
     * @param int $offset
     * @return int
     */
    public function writeUInt32LE(int $value, int $offset = 0): int
    {
        $this->assertRange('writeUInt32LE', $offset, 4);

        $this->bytes[$offset] = chr($value & 0xFF);
        $this->bytes[$offset + 1] = chr(($value >> 8) & 0xFF);
        $this->bytes[$offset + 2] = chr(($value >> 16) & 0xFF);
        $this->bytes[$offset + 3] = chr(($value >> 24) & 0xFF);
        return $offset + 4;
    }

    /**
     * Reads a 32-bit float (big-endian) from buf at the specified offset.
     *
     * @param int $offset
     * @return float
     */
    public function readFloatBE(int $offset = 0): float
    {
        $this->assertRange('readFloatBE', $offset, 4);

        return unpack('f', strrev(substr($this->bytes, $offset, 4)))[1];
    }

    /**
     * Reads a 32-bit float (little-endian) from buf at the specified offset.
     *
     * @param int $offset
     * @return float
     */
    public function readFloatLE(int $offset = 0): float
    {
        $this->assertRange('readFloatLE', $offset, 4);

        return unpack('f', substr($this->bytes, $offset, 4))[1];
    }

    /**
     * Reads a 64-bit double (big-endian) from buf at the specified offset.
     *
     * @param int $offset
     * @return float
     */
    public function readDoubleBE(int $offset = 0): float
    {
        $this->assertRange('readDoubleBE', $offset, 8);

        return unpack('d', strrev(substr($this->bytes, $offset, 8)))[1];
    }

    /**
     * Reads a 64-bit double (little-endian) from buf at the specified offset.
     *
     * @param int $offset
     * @return float
     */
    public function readDoubleLE(int $offset = 0): float
    {
        $this->assertRange('readDoubleLE', $offset, 8);

        return unpack('d', substr($this->bytes, $offset, 8))[1];
    }

    /**
     * Writes value to buf at the specified offset as a 32-bit float (big-endian).
     *
     * @param float $value
     * @param int $offset
     * @return int
     */
    public function writeFloatBE(float $value, int $offset = 0): int
    {
        $this->assertRange('writeFloatBE', $offset, 4);

        $this->bytes = substr_replace($this->bytes, strrev(pack('f', $value)), $offset, 4);

        return $offset + 4;
    }

    /**
     * Writes value to buf at the specified offset as a 32-bit float (little-endian).
     *
     * @param float $value
     * @param int $offset
     * @return int
     */
    public function writeFloatLE(float $value, int $offset = 0): int
    {
        $this->assertRange('writeFloatLE', $offset, 4);

        $this->bytes = substr_replace($this->bytes, pack('f', $value), $offset, 4);

        return $offset + 4;
    }

    /**
     * Writes value to buf at the specified offset as a 64-bit double (big-endian).
     *
     * @param float $value
     * @param int $offset
     * @return int
     */
    public function writeDoubleBE(float $value, int $offset = 0): int
    {
        $this->assertRange('writeDoubleBE', $offset, 8);

        $this->bytes = substr_replace($this->bytes, strrev(pack('d', $value)), $offset, 8);

        return $offset + 8;
    }

    /**
     * Writes value to buf at the specified offset as a 64-bit double (little-endian).
     *
     * @param float $value
     * @param int $offset
     * @return int
     */
    public function writeDoubleLE(float $value, int $offset = 0): int
    {
        $this->assertRange('writeDoubleLE', $offset, 8);

        $this->bytes = substr_replace($this->bytes, pack('d', $value), $offset, 8);

        return $offset + 8;
    }

    /**
     * Reads byteLength bytes as an unsigned big-endian integer.
     *
     * @param int $offset
     * @param int $byteLength Between 1 and 6.
     * @return int
     * @throws InvalidArgumentException|OutOfBoundsException
     */
    public function readUIntBE(int $offset = 0, int $byteLength = 1): int
    {
        self::assertByteLength($byteLength);
        $this->assertRange('readUIntBE', $offset, $byteLength);

        $value = 0;
        for ($i = 0; $i < $byteLength; $i++) {
            $value = ($value << 8) | ord($this->bytes[$offset + $i]);
        }

        return $value;
    }

    /**
     * Reads byteLength bytes as an unsigned little-endian integer.
     *
     * @param int $offset
     * @param int $byteLength Between 1 and 6.
     * @return int
     * @throws InvalidArgumentException|OutOfBoundsException
     */
    public function readUIntLE(int $offset = 0, int $byteLength = 1): int
    {
        self::assertByteLength($byteLength);
        $this->assertRange('readUIntLE', $offset, $byteLength);

        $value = 0;
        for ($i = $byteLength - 1; $i >= 0; $i--) {
            $value = ($value << 8) | ord($this->bytes[$offset + $i]);
        }

        return $value;
    }

    /**
     * Reads byteLength bytes as a signed big-endian integer.
     *
     * @param int $offset
     * @param int $byteLength Between 1 and 6.
     * @return int
     * @throws InvalidArgumentException|OutOfBoundsException
     */
    public function readIntBE(int $offset = 0, int $byteLength = 1): int
    {
        return self::signExtend($this->readUIntBE($offset, $byteLength), $byteLength);
    }

    /**
     * Reads byteLength bytes as a signed little-endian integer.
     *
     * @param int $offset
     * @param int $byteLength Between 1 and 6.
     * @return int
     * @throws InvalidArgumentException|OutOfBoundsException
     */
    public function readIntLE(int $offset = 0, int $byteLength = 1): int
    {
        return self::signExtend($this->readUIntLE($offset, $byteLength), $byteLength);
    }

    /**
     * Writes value as a big-endian integer of byteLength bytes.
     *
     * @param int $value
     * @param int $offset
     * @param int $byteLength Between 1 and 6.
     * @return int Offset past the write.
     * @throws InvalidArgumentException|OutOfBoundsException
     */
    public function writeUIntBE(int $value, int $offset = 0, int $byteLength = 1): int
    {
        self::assertByteLength($byteLength);
        $this->assertRange('writeUIntBE', $offset, $byteLength);

        for ($i = $byteLength - 1; $i >= 0; $i--) {
            $this->bytes[$offset + $i] = chr($value & 0xFF);
            $value >>= 8;
        }

        return $offset + $byteLength;
    }

    /**
     * Writes value as a little-endian integer of byteLength bytes.
     *
     * @param int $value
     * @param int $offset
     * @param int $byteLength Between 1 and 6.
     * @return int Offset past the write.
     * @throws InvalidArgumentException|OutOfBoundsException
     */
    public function writeUIntLE(int $value, int $offset = 0, int $byteLength = 1): int
    {
        self::assertByteLength($byteLength);
        $this->assertRange('writeUIntLE', $offset, $byteLength);

        for ($i = 0; $i < $byteLength; $i++) {
            $this->bytes[$offset + $i] = chr($value & 0xFF);
            $value >>= 8;
        }

        return $offset + $byteLength;
    }

    /**
     * Alias of writeUIntBE(); the byte pattern for a signed value is identical.
     *
     * @param int $value
     * @param int $offset
     * @param int $byteLength Between 1 and 6.
     * @return int Offset past the write.
     * @throws InvalidArgumentException|OutOfBoundsException
     */
    public function writeIntBE(int $value, int $offset = 0, int $byteLength = 1): int
    {
        return $this->writeUIntBE($value, $offset, $byteLength);
    }

    /**
     * Alias of writeUIntLE(); the byte pattern for a signed value is identical.
     *
     * @param int $value
     * @param int $offset
     * @param int $byteLength Between 1 and 6.
     * @return int Offset past the write.
     * @throws InvalidArgumentException|OutOfBoundsException
     */
    public function writeIntLE(int $value, int $offset = 0, int $byteLength = 1): int
    {
        return $this->writeUIntLE($value, $offset, $byteLength);
    }

    /**
     * Reads an unsigned 64-bit big-endian integer.
     *
     * Returns a BigInteger rather than an int, because unsigned 64-bit values
     * exceed PHP's signed int range. This is the replacement for toInt() on
     * wide values.
     *
     * @param int $offset
     * @return BigInteger
     * @throws OutOfBoundsException
     */
    public function readBigUInt64BE(int $offset = 0): BigInteger
    {
        $this->assertRange('readBigUInt64BE', $offset, 8);

        return BigInteger::fromBase(bin2hex(substr($this->bytes, $offset, 8)), 16);
    }

    /**
     * Reads an unsigned 64-bit little-endian integer.
     *
     * @param int $offset
     * @return BigInteger
     * @throws OutOfBoundsException
     */
    public function readBigUInt64LE(int $offset = 0): BigInteger
    {
        $this->assertRange('readBigUInt64LE', $offset, 8);

        return BigInteger::fromBase(bin2hex(strrev(substr($this->bytes, $offset, 8))), 16);
    }

    /**
     * Reads a signed 64-bit big-endian integer.
     *
     * @param int $offset
     * @return BigInteger
     * @throws OutOfBoundsException
     */
    public function readBigInt64BE(int $offset = 0): BigInteger
    {
        return self::toSigned64($this->readBigUInt64BE($offset));
    }

    /**
     * Reads a signed 64-bit little-endian integer.
     *
     * @param int $offset
     * @return BigInteger
     * @throws OutOfBoundsException
     */
    public function readBigInt64LE(int $offset = 0): BigInteger
    {
        return self::toSigned64($this->readBigUInt64LE($offset));
    }

    /**
     * Writes an unsigned 64-bit big-endian integer.
     *
     * @param BigInteger|int|string $value
     * @param int $offset
     * @return int Offset past the write.
     * @throws OutOfBoundsException
     */
    public function writeBigUInt64BE(BigInteger|int|string $value, int $offset = 0): int
    {
        $this->assertRange('writeBigUInt64BE', $offset, 8);
        $this->bytes = substr_replace($this->bytes, self::toEightBytes($value), $offset, 8);

        return $offset + 8;
    }

    /**
     * Writes an unsigned 64-bit little-endian integer.
     *
     * @param BigInteger|int|string $value
     * @param int $offset
     * @return int Offset past the write.
     * @throws OutOfBoundsException
     */
    public function writeBigUInt64LE(BigInteger|int|string $value, int $offset = 0): int
    {
        $this->assertRange('writeBigUInt64LE', $offset, 8);
        $this->bytes = substr_replace($this->bytes, strrev(self::toEightBytes($value)), $offset, 8);

        return $offset + 8;
    }

    /**
     * Writes a signed 64-bit big-endian integer.
     *
     * @param BigInteger|int|string $value
     * @param int $offset
     * @return int Offset past the write.
     * @throws OutOfBoundsException
     */
    public function writeBigInt64BE(BigInteger|int|string $value, int $offset = 0): int
    {
        return $this->writeBigUInt64BE(self::toUnsigned64($value), $offset);
    }

    /**
     * Writes a signed 64-bit little-endian integer.
     *
     * @param BigInteger|int|string $value
     * @param int $offset
     * @return int Offset past the write.
     * @throws OutOfBoundsException
     */
    public function writeBigInt64LE(BigInteger|int|string $value, int $offset = 0): int
    {
        return $this->writeBigUInt64LE(self::toUnsigned64($value), $offset);
    }

    /**
     * JSON representation, matching Node's Buffer#toJSON().
     *
     * @return array{type: string, data: list<int>}
     */
    public function toJSON(): array
    {
        return ['type' => 'Buffer', 'data' => $this->toArray()];
    }

    /**
     * Iterates over the buffer indices.
     *
     * @return Generator<int, int>
     */
    public function keys(): Generator
    {
        for ($i = 0; $i < $this->length; $i++) {
            yield $i;
        }
    }

    /**
     * Iterates over the byte values.
     *
     * @return Generator<int, int>
     */
    public function values(): Generator
    {
        for ($i = 0; $i < $this->length; $i++) {
            yield ord($this->bytes[$i]);
        }
    }

    /**
     * Iterates over [index, byte] pairs.
     *
     * @return Generator<int, array{int, int}>
     */
    public function entries(): Generator
    {
        for ($i = 0; $i < $this->length; $i++) {
            yield [$i, ord($this->bytes[$i])];
        }
    }

    /**
     * Re-encodes a buffer from one character encoding to another.
     *
     * Requires ext-mbstring. Unlike Node, which silently substitutes
     * characters that the target encoding cannot represent, this delegates to
     * mb_convert_encoding() and inherits its substitution behaviour.
     *
     * @param Buffer $source
     * @param string $fromEncoding
     * @param string $toEncoding
     * @return Buffer
     * @throws BufferException If ext-mbstring is unavailable.
     */
    public static function transcode(Buffer $source, string $fromEncoding, string $toEncoding): Buffer
    {
        if (!function_exists('mb_convert_encoding')) {
            throw new BufferException('Buffer::transcode() requires ext-mbstring.');
        }

        return self::wrap((string)mb_convert_encoding($source->bytes, $toEncoding, $fromEncoding));
    }

    /**
     * @param int $byteLength
     * @return void
     * @throws InvalidArgumentException
     */
    private static function assertByteLength(int $byteLength): void
    {
        if ($byteLength < 1 || $byteLength > 6) {
            throw new InvalidArgumentException(sprintf(
                'The value of "byteLength" is out of range. It must be between 1 and 6, %d given. '
                . 'Use the readBigUInt64/readBigInt64 family for wider values.',
                $byteLength
            ));
        }
    }

    /**
     * @param int $value
     * @param int $byteLength
     * @return int
     */
    private static function signExtend(int $value, int $byteLength): int
    {
        $signBit = 1 << ($byteLength * 8 - 1);

        return $value >= $signBit ? $value - ($signBit << 1) : $value;
    }

    /**
     * @param BigInteger $value
     * @return BigInteger
     */
    private static function toSigned64(BigInteger $value): BigInteger
    {
        $limit = BigInteger::of(2)->power(63);

        return $value->isGreaterThanOrEqualTo($limit) ? $value->minus($limit->multipliedBy(2)) : $value;
    }

    /**
     * @param BigInteger|int|string $value
     * @return BigInteger
     */
    private static function toUnsigned64(BigInteger|int|string $value): BigInteger
    {
        $big = $value instanceof BigInteger ? $value : BigInteger::of($value);

        return $big->isNegative() ? $big->plus(BigInteger::of(2)->power(64)) : $big;
    }

    /**
     * @param BigInteger|int|string $value
     * @return string Exactly eight raw bytes, big-endian.
     */
    private static function toEightBytes(BigInteger|int|string $value): string
    {
        $big = $value instanceof BigInteger ? $value : BigInteger::of($value);
        $hex = $big->toBase(16);

        return (string)hex2bin(str_pad($hex, 16, '0', STR_PAD_LEFT));
    }

    /**
     * Returns a string summary of the buffer.
     *
     * @return string
     */
    public function debug(): string
    {
        return 'Buffer(length=' . $this->length . ', data=[' . implode(', ', $this->toArray()) . '])';
    }

    /**
     * Returns the buffer content as an SplFixedArray of integers.
     *
     * Kept for backwards compatibility. The bytes are held as a string
     * internally, so this converts on the fly rather than exposing the storage.
     * toArray() is cheaper and getLength() answers the usual reason for calling
     * this.
     *
     * @return SplFixedArray
     */
    public function getBytesArray(): SplFixedArray
    {
        return SplFixedArray::fromArray($this->toArray());
    }

    /**
     * Replaces the buffer content from an SplFixedArray of integers.
     *
     * Kept for backwards compatibility, see getBytesArray().
     *
     * @param SplFixedArray $bytesArray
     * @return void
     */
    public function setBytesArray(SplFixedArray $bytesArray): void
    {
        $raw = '';
        foreach ($bytesArray as $byte) {
            $raw .= chr((int)$byte & 0xFF);
        }

        $this->bytes = $raw;
        $this->length = strlen($raw);
    }

    /**
     * Whether an offset exists
     *
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return is_int($offset) && $offset >= 0 && $offset < $this->length;
    }

    /**
     * Offset to retrieve
     *
     * @param mixed $offset
     * @return int
     * @throws Exception
     */
    public function offsetGet(mixed $offset): int
    {
        if (!is_int($offset) || $offset < 0 || $offset >= $this->length) {
            throw OutOfBoundsException::forRange('offsetGet', is_int($offset) ? $offset : 0, 1, $this->length);
        }

        return ord($this->bytes[$offset]);
    }

    /**
     * Offset to set
     *
     * Buffers are fixed size, as in Node. A write outside the buffer is a
     * silent no-op rather than a reallocation, so a mistyped index cannot
     * change the buffer's length. Use set() or appendBuffer() to grow a buffer.
     *
     * @param mixed $offset
     * @param mixed $value
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!is_int($offset) || $offset < 0 || $offset >= $this->length) {
            return;
        }

        $this->bytes[$offset] = chr((int)$value & 0xFF);
    }

    /**
     * Offset to unset
     *
     * No-op. Node has no concept of unsetting a byte, and removing one here
     * would leave a null in the storage that only surfaces on a later read.
     * Write 0x00 explicitly if that is what you mean.
     *
     * @param mixed $offset
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
    }
}