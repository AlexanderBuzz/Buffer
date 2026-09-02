<?php declare(strict_types=1);
namespace Bench;

use Brick\Math\BigInteger;
use Exception;
use SplFixedArray;

/**
 * String-backed prototype of the hot paths of Hardcastle\Buffer\Buffer.
 * Same public behaviour, bytes held in a PHP string instead of SplFixedArray.
 */
class BufferStr implements \ArrayAccess
{
    public const DEFAULT_FILL = 0x00;

    private string $bytes;
    private int $length;

    public function __construct(int $length = 0)
    {
        $this->length = max(0, $length);
        $this->bytes = str_repeat("\x00", $this->length);
    }

    public static function alloc(int $size = 0, int|string|self $fill = self::DEFAULT_FILL, string $encoding = 'utf-8'): self
    {
        $buffer = new self($size);
        if ($size > 0 && $fill !== self::DEFAULT_FILL) {
            $buffer->fill($fill, 0, $size, $encoding);
        }
        return $buffer;
    }

    public function fill(int|string|self $value, int $offset = 0, ?int $end = null, string $encoding = 'utf-8'): self
    {
        $end ??= $this->length;
        $pattern = match (true) {
            is_int($value) => chr($value & 0xFF),
            $value instanceof self => $value->bytes,
            default => $encoding === 'hex' ? (string)hex2bin($value) : $value,
        };
        if ($pattern === '' || $end <= $offset) {
            return $this;
        }
        $span = $end - $offset;
        $filled = substr(str_repeat($pattern, (int)ceil($span / strlen($pattern))), 0, $span);
        $this->bytes = substr_replace($this->bytes, $filled, $offset, $span);
        return $this;
    }

    /** Raw string constructor — the fast path the codec would use. */
    private static function wrap(string $raw): self
    {
        $b = new self(0);
        $b->bytes = $raw;
        $b->length = strlen($raw);
        return $b;
    }

    public static function from(mixed $source, ?string $encoding = 'utf-8'): self
    {
        if ($source instanceof self) {
            return self::wrap($source->bytes);           // was: per-byte loop
        }

        if (is_array($source)) {
            $raw = '';
            foreach ($source as $b) {
                $raw .= chr((int)$b & 0xFF);
            }
            return self::wrap($raw);
        }

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
                return self::wrap((string)hex2bin($source)); // was: str_split + array_map + loop
            }
            if ($encoding === 'base64') {
                $source = (string)base64_decode($source);
            }
            return self::wrap($source);                   // was: unpack('C*') + loop
        }

        throw new Exception('Buffer does not support source type: ' . gettype($source));
    }

    public static function concat(array $bufferList, ?int $totalLength = null): self
    {
        if (empty($bufferList)) {
            return new self(0);
        }
        $raw = '';
        foreach ($bufferList as $buffer) {
            if ($buffer instanceof self) {
                $raw .= $buffer->bytes;
            }
        }
        if ($totalLength !== null && strlen($raw) > $totalLength) {
            $raw = substr($raw, 0, $totalLength);
        } elseif ($totalLength !== null && strlen($raw) < $totalLength) {
            $raw = str_pad($raw, $totalLength, "\x00");
        }
        return self::wrap($raw);
    }

    public function getLength(): int
    {
        return $this->length;
    }

    public function subArray(int $start = 0, ?int $end = null): self
    {
        $end ??= $this->length;
        if ($start < 0) { $start = max(0, $this->length + $start); }
        if ($end < 0)   { $end = max(0, $this->length + $end); }
        $length = max(0, min($end - $start, $this->length - $start)); // clamped (fixes the 1.x ValueError)
        return self::wrap(substr($this->bytes, $start, $length));
    }

    public function slice(int $start = 0, ?int $end = null): self
    {
        return $this->subArray($start, $end);
    }

    public function toString(string $encoding = 'hex', int $start = 0, ?int $end = null): string
    {
        $end ??= $this->length;
        $raw = ($start === 0 && $end === $this->length)
            ? $this->bytes
            : substr($this->bytes, $start, max(0, min($end - $start, $this->length - $start)));

        if ($encoding === 'hex') {
            return strtoupper(bin2hex($raw));            // was: dechex + str_pad per byte
        }
        if ($encoding === 'base64') {
            return base64_encode($raw);
        }
        return $raw;
    }

    public function toArray(): array
    {
        return $this->length === 0 ? [] : array_values(unpack('C*', $this->bytes));
    }

    public function toUtf8(): string
    {
        return $this->bytes;                             // was: chr() concat per byte
    }

    public function toInt(): int
    {
        return (int)BigInteger::fromBase($this->toString('hex'), 16)->toBase(10);
    }

    public function readUInt8(int $offset = 0): int
    {
        return ord($this->bytes[$offset]);
    }

    public function readUInt16BE(int $offset = 0): int
    {
        return (ord($this->bytes[$offset]) << 8) | ord($this->bytes[$offset + 1]);
    }

    public function readUInt32BE(int $offset = 0): int
    {
        return unpack('N', $this->bytes, $offset)[1];
    }

    public function appendBuffer(self $appendix): void
    {
        $this->bytes .= $appendix->bytes;
        $this->length += $appendix->length;
    }

    public function appendHex(string $hexBytes): void
    {
        $this->appendBuffer(self::from($hexBytes, 'hex'));
    }

    public function equals(self $other): bool
    {
        return $this->bytes === $other->bytes;
    }

    // --- BC adapters the user asked about: same signatures, cast on the fly ---

    public function getBytesArray(): SplFixedArray
    {
        return SplFixedArray::fromArray($this->toArray());
    }

    public function setBytesArray(SplFixedArray $bytesArray): void
    {
        $raw = '';
        foreach ($bytesArray as $b) {
            $raw .= chr((int)$b & 0xFF);
        }
        $this->bytes = $raw;
        $this->length = strlen($raw);
    }

    public function offsetExists(mixed $offset): bool { return $offset >= 0 && $offset < $this->length; }
    public function offsetGet(mixed $offset): int
    {
        if (!$this->offsetExists($offset)) { throw new Exception('Requested Buffer element out of bounds'); }
        return ord($this->bytes[$offset]);
    }
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_int($offset) && $offset >= 0 && $offset < $this->length) {
            $this->bytes[$offset] = chr((int)$value & 0xFF);
        }
    }
    public function offsetUnset(mixed $offset): void { /* no-op, as in Node */ }
}
