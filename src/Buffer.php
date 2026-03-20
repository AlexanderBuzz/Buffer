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
use SplFixedArray;

/**
 * Implements the functionality of Node.js Buffer (https://nodejs.org/api/buffer.html).
 * @template-implements ArrayAccess<int, int>
 */
class Buffer implements ArrayAccess
{
    public const DEFAULT_FILL = 0x00;

    /**
     * @var int
     */
    public int $length;

    /**
     * @var SplFixedArray
     */
    private SplFixedArray $bytesArray;

    /**
     * Buffer constructor.
     *
     * @param int $length
     */
    public function __construct(int $length = 0)
    {
        $this->length = $length;
        $this->bytesArray = new SplFixedArray($length);
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
        } else {
            for ($i = 0; $i < $size; $i++) {
                $buffer->bytesArray[$i] = self::DEFAULT_FILL;
            }
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
            $buffer = new self($source->length);
            for ($i = 0; $i < $source->length; $i++) {
                $buffer->bytesArray[$i] = $source->bytesArray[$i];
            }
            return $buffer;
        }

        // Buffer from byte array [12, 108, 0, 230]
        if (is_array($source)) {
            $length = count($source);
            $buffer = new self($length);
            for ($i = 0; $i < $length; $i++) {
                $buffer->bytesArray[$i] = (int)$source[$i] & 0xFF;
            }
            return $buffer;
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
                $tempArray = array_map('hexdec', str_split($source, 2));
                return self::from($tempArray);
            }

            if ($encoding === 'base64') {
                $source = base64_decode($source);
                // After decoding base64, we treat it as a raw string
            }

            // Treat as raw binary string/UTF-8
            $tempArray = array_values(unpack('C*', $source));
            return self::from($tempArray);
        }

        throw new Exception('Buffer does not support source type: ' . gettype($source));
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

        $result = new self($totalLength);
        $offset = 0;
        foreach ($bufferList as $buffer) {
            if ($buffer instanceof Buffer) {
                $copyLength = min($buffer->length, $totalLength - $offset);
                if ($copyLength <= 0) {
                    break;
                }
                for ($i = 0; $i < $copyLength; $i++) {
                    $result->bytesArray[$offset + $i] = $buffer->bytesArray[$i];
                }
                $offset += $copyLength;
            }
        }

        return $result;
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

        if (is_int($value)) {
            $byte = $value & 0xFF;
            for ($i = $offset; $i < $end; $i++) {
                $this->bytesArray[$i] = $byte;
            }
        } elseif (is_string($value) || $value instanceof Buffer) {
            $fillBuf = $value instanceof Buffer ? $value : self::from($value, $encoding);
            if ($fillBuf->length === 0) {
                for ($i = $offset; $i < $end; $i++) {
                    $this->bytesArray[$i] = 0;
                }
            } else {
                for ($i = $offset; $i < $end; $i++) {
                    $this->bytesArray[$i] = $fillBuf->bytesArray[($i - $offset) % $fillBuf->length];
                }
            }
        }

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

        for ($i = 0; $i < $length; $i++) {
            $this->bytesArray[$offset + $i] = $writeBuf->bytesArray[$i];
        }

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

        if ($this === $target && $targetStart > $sourceStart) {
            for ($i = $copyLength - 1; $i >= 0; $i--) {
                $target->bytesArray[$targetStart + $i] = $this->bytesArray[$sourceStart + $i];
            }
        } else {
            for ($i = 0; $i < $copyLength; $i++) {
                $target->bytesArray[$targetStart + $i] = $this->bytesArray[$sourceStart + $i];
            }
        }

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

        for ($i = 0; $i < $len; $i++) {
            $sByte = $this->bytesArray[$sourceStart + $i];
            $tByte = $target->bytesArray[$targetStart + $i];
            if ($sByte !== $tByte) {
                return $sByte < $tByte ? -1 : 1;
            }
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
        $newLength = $this->length + $appendix->length;
        $newBytesArray = new SplFixedArray($newLength);

        for ($i = 0; $i < $this->length; $i++) {
            $newBytesArray[$i] = $this->bytesArray[$i];
        }

        for ($i = 0; $i < $appendix->length; $i++) {
            $newBytesArray[$this->length + $i] = $appendix->bytesArray[$i];
        }

        $this->bytesArray = $newBytesArray;
        $this->length = $newLength;
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
        $newLength = $this->length + $prefix->length;
        $newBytesArray = new SplFixedArray($newLength);

        for ($i = 0; $i < $prefix->length; $i++) {
            $newBytesArray[$i] = $prefix->bytesArray[$i];
        }

        for ($i = 0; $i < $this->length; $i++) {
            $newBytesArray[$prefix->length + $i] = $this->bytesArray[$i];
        }

        $this->bytesArray = $newBytesArray;
        $this->length = $newLength;
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
            $newBytesArray = new SplFixedArray($newLength);
            for ($i = 0; $i < $this->length; $i++) {
                $newBytesArray[$i] = $this->bytesArray[$i];
            }
            // Fill gaps if any
            for ($i = $this->length; $i < $startIdx; $i++) {
                $newBytesArray[$i] = self::DEFAULT_FILL;
            }
            $this->bytesArray = $newBytesArray;
            $this->length = $newLength;
        }

        for ($i = 0; $i < $bytesLength; $i++) {
            $this->bytesArray[$startIdx + $i] = (int)$bytes[$i] & 0xFF;
        }
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

        $length = max(0, $end - $start);
        $length = min($length, $this->length - $start);

        $newBuffer = new self($length);
        for ($i = 0; $i < $length; $i++) {
            $newBuffer->bytesArray[$i] = $this->bytesArray[$start + $i];
        }

        return $newBuffer;
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
        $buf = ($start === 0 && $end === $this->length) ? $this : $this->subArray($start, $end);

        if ($encoding === 'hex') {
            $hex = '';
            for ($i = 0; $i < $buf->length; $i++) {
                $hex .= str_pad(dechex($buf->bytesArray[$i]), 2, '0', STR_PAD_LEFT);
            }
            return strtoupper($hex);
        }

        if ($encoding === 'base64') {
            return base64_encode($buf->toUtf8());
        }

        if ($encoding === 'utf8' || $encoding === 'utf-8') {
            return $buf->toUtf8();
        }

        return $buf->toUtf8();
    }

    /**
     * Returns the content as byte array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->bytesArray->toArray();
    }

    /**
     * Returns the buffer content as big integer.
     *
     * @return int
     */
    public function toInt(): int
    {
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
        $chars = '';
        for ($i = 0; $i < $this->length; $i++) {
            $chars .= chr($this->bytesArray[$i]);
        }
        return $chars;
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

        for ($i = 0; $i < $this->length; $i += 2) {
            $tmp = $this->bytesArray[$i];
            $this->bytesArray[$i] = $this->bytesArray[$i + 1];
            $this->bytesArray[$i + 1] = $tmp;
        }

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

        for ($i = 0; $i < $this->length; $i += 4) {
            $tmp0 = $this->bytesArray[$i];
            $tmp1 = $this->bytesArray[$i + 1];
            $this->bytesArray[$i] = $this->bytesArray[$i + 3];
            $this->bytesArray[$i + 1] = $this->bytesArray[$i + 2];
            $this->bytesArray[$i + 2] = $tmp1;
            $this->bytesArray[$i + 3] = $tmp0;
        }

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

        for ($i = 0; $i < $this->length; $i += 8) {
            for ($j = 0; $j < 4; $j++) {
                $tmp = $this->bytesArray[$i + $j];
                $this->bytesArray[$i + $j] = $this->bytesArray[$i + 7 - $j];
                $this->bytesArray[$i + 7 - $j] = $tmp;
            }
        }

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

        for ($i = $byteOffset; $i <= $this->length - $valBuf->length; $i++) {
            $match = true;
            for ($j = 0; $j < $valBuf->length; $j++) {
                if ($this->bytesArray[$i + $j] !== $valBuf->bytesArray[$j]) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                return $i;
            }
        }

        return -1;
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

        for ($i = $byteOffset; $i >= 0; $i--) {
            $match = true;
            for ($j = 0; $j < $valBuf->length; $j++) {
                if ($this->bytesArray[$i + $j] !== $valBuf->bytesArray[$j]) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * Reads an 8-bit integer from buf at the specified offset.
     *
     * @param int $offset
     * @return int
     */
    public function readInt8(int $offset = 0): int
    {
        $val = $this->bytesArray[$offset];
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
        return $this->bytesArray[$offset];
    }

    /**
     * Reads a signed 16-bit integer (big-endian) from buf at the specified offset.
     *
     * @param int $offset
     * @return int
     */
    public function readInt16BE(int $offset = 0): int
    {
        $val = ($this->bytesArray[$offset] << 8) | $this->bytesArray[$offset + 1];
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
        $val = ($this->bytesArray[$offset + 1] << 8) | $this->bytesArray[$offset];
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
        return ($this->bytesArray[$offset] << 8) | $this->bytesArray[$offset + 1];
    }

    /**
     * Reads an unsigned 16-bit integer (little-endian) from buf at the specified offset.
     *
     * @param int $offset
     * @return int
     */
    public function readUInt16LE(int $offset = 0): int
    {
        return ($this->bytesArray[$offset + 1] << 8) | $this->bytesArray[$offset];
    }

    /**
     * Reads a signed 32-bit integer (big-endian) from buf at the specified offset.
     *
     * @param int $offset
     * @return int
     */
    public function readInt32BE(int $offset = 0): int
    {
        $val = ($this->bytesArray[$offset] << 24) | ($this->bytesArray[$offset + 1] << 16) | ($this->bytesArray[$offset + 2] << 8) | $this->bytesArray[$offset + 3];
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
        $val = ($this->bytesArray[$offset + 3] << 24) | ($this->bytesArray[$offset + 2] << 16) | ($this->bytesArray[$offset + 1] << 8) | $this->bytesArray[$offset];
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
        return (($this->bytesArray[$offset] << 24) | ($this->bytesArray[$offset + 1] << 16) | ($this->bytesArray[$offset + 2] << 8) | $this->bytesArray[$offset + 3]) & 0xFFFFFFFF;
    }

    /**
     * Reads an unsigned 32-bit integer (little-endian) from buf at the specified offset.
     *
     * @param int $offset
     * @return int
     */
    public function readUInt32LE(int $offset = 0): int
    {
        return (($this->bytesArray[$offset + 3] << 24) | ($this->bytesArray[$offset + 2] << 16) | ($this->bytesArray[$offset + 1] << 8) | $this->bytesArray[$offset]) & 0xFFFFFFFF;
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
        $this->bytesArray[$offset] = $value & 0xFF;
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
        $this->bytesArray[$offset] = $value & 0xFF;
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
        $this->bytesArray[$offset] = ($value >> 8) & 0xFF;
        $this->bytesArray[$offset + 1] = $value & 0xFF;
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
        $this->bytesArray[$offset] = $value & 0xFF;
        $this->bytesArray[$offset + 1] = ($value >> 8) & 0xFF;
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
        $this->bytesArray[$offset] = ($value >> 8) & 0xFF;
        $this->bytesArray[$offset + 1] = $value & 0xFF;
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
        $this->bytesArray[$offset] = $value & 0xFF;
        $this->bytesArray[$offset + 1] = ($value >> 8) & 0xFF;
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
        $this->bytesArray[$offset] = ($value >> 24) & 0xFF;
        $this->bytesArray[$offset + 1] = ($value >> 16) & 0xFF;
        $this->bytesArray[$offset + 2] = ($value >> 8) & 0xFF;
        $this->bytesArray[$offset + 3] = $value & 0xFF;
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
        $this->bytesArray[$offset] = $value & 0xFF;
        $this->bytesArray[$offset + 1] = ($value >> 8) & 0xFF;
        $this->bytesArray[$offset + 2] = ($value >> 16) & 0xFF;
        $this->bytesArray[$offset + 3] = ($value >> 24) & 0xFF;
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
        $this->bytesArray[$offset] = ($value >> 24) & 0xFF;
        $this->bytesArray[$offset + 1] = ($value >> 16) & 0xFF;
        $this->bytesArray[$offset + 2] = ($value >> 8) & 0xFF;
        $this->bytesArray[$offset + 3] = $value & 0xFF;
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
        $this->bytesArray[$offset] = $value & 0xFF;
        $this->bytesArray[$offset + 1] = ($value >> 8) & 0xFF;
        $this->bytesArray[$offset + 2] = ($value >> 16) & 0xFF;
        $this->bytesArray[$offset + 3] = ($value >> 24) & 0xFF;
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
        $data = pack('CCCC', $this->bytesArray[$offset], $this->bytesArray[$offset + 1], $this->bytesArray[$offset + 2], $this->bytesArray[$offset + 3]);
        return unpack('f', strrev($data))[1];
    }

    /**
     * Reads a 32-bit float (little-endian) from buf at the specified offset.
     *
     * @param int $offset
     * @return float
     */
    public function readFloatLE(int $offset = 0): float
    {
        $data = pack('CCCC', $this->bytesArray[$offset], $this->bytesArray[$offset + 1], $this->bytesArray[$offset + 2], $this->bytesArray[$offset + 3]);
        return unpack('f', $data)[1];
    }

    /**
     * Reads a 64-bit double (big-endian) from buf at the specified offset.
     *
     * @param int $offset
     * @return float
     */
    public function readDoubleBE(int $offset = 0): float
    {
        $data = '';
        for ($i = 0; $i < 8; $i++) {
            $data .= chr($this->bytesArray[$offset + $i]);
        }
        return unpack('d', strrev($data))[1];
    }

    /**
     * Reads a 64-bit double (little-endian) from buf at the specified offset.
     *
     * @param int $offset
     * @return float
     */
    public function readDoubleLE(int $offset = 0): float
    {
        $data = '';
        for ($i = 0; $i < 8; $i++) {
            $data .= chr($this->bytesArray[$offset + $i]);
        }
        return unpack('d', $data)[1];
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
        $data = strrev(pack('f', $value));
        for ($i = 0; $i < 4; $i++) {
            $this->bytesArray[$offset + $i] = ord($data[$i]);
        }
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
        $data = pack('f', $value);
        for ($i = 0; $i < 4; $i++) {
            $this->bytesArray[$offset + $i] = ord($data[$i]);
        }
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
        $data = strrev(pack('d', $value));
        for ($i = 0; $i < 8; $i++) {
            $this->bytesArray[$offset + $i] = ord($data[$i]);
        }
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
        $data = pack('d', $value);
        for ($i = 0; $i < 8; $i++) {
            $this->bytesArray[$offset + $i] = ord($data[$i]);
        }
        return $offset + 8;
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
     * Returns the internal SplFixedArray.
     *
     * @return SplFixedArray
     */
    public function getBytesArray(): SplFixedArray
    {
        return $this->bytesArray;
    }

    /**
     * Sets the internal SplFixedArray.
     *
     * @param SplFixedArray $bytesArray
     * @return void
     */
    public function setBytesArray(SplFixedArray $bytesArray): void
    {
        $this->bytesArray = $bytesArray;
        $this->length = $bytesArray->getSize();
    }

    /**
     * Whether an offset exists
     *
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->bytesArray[$offset]);
    }

    /**
     * Offset to retrieve
     *
     * @param mixed $offset
     * @return int
     * @throws Exception
     */
    public function offsetGet(mixed $offset): int //TODO: If this is to replace node buffer, type may be mixed
    {
        if (!isset($this->bytesArray[$offset])) {
            throw new Exception('Requested Buffer element out of bounds');
        }
        return $this->bytesArray[$offset];
    }

    /**
     * Offset to set
     *
     * @param mixed $offset
     * @param mixed $value
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_int($offset) && $offset >= 0) {
            if ($offset >= $this->length) {
                $newBytesArray = new SplFixedArray($offset + 1);
                for ($i = 0; $i < $this->length; $i++) {
                    $newBytesArray[$i] = $this->bytesArray[$i];
                }
                for ($i = $this->length; $i < $offset; $i++) {
                    $newBytesArray[$i] = self::DEFAULT_FILL;
                }
                $this->bytesArray = $newBytesArray;
                $this->length = $offset + 1;
            }
            $this->bytesArray[$offset] = (int)$value & 0xFF;
        }
    }

    /**
     * Offset to unset
     *
     * @param mixed $offset
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->bytesArray[$offset]);
    }
}