<?php declare(strict_types=1);
/**
 * PHP Buffer
 *
 * Copyright (c) Alexander Busse | Hardcastle Technologies
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Hardcastle\Buffer\Exception;

/**
 * Thrown when an offset or range falls outside the buffer.
 *
 * This is the equivalent of Node's ERR_OUT_OF_RANGE and is thrown uniformly by
 * every accessor: ArrayAccess reads as well as the read*() and write*() family.
 *
 * @psalm-api
 */
class OutOfBoundsException extends BufferException
{
    /**
     * @param string $operation Name of the accessor that was called.
     * @param int $offset Requested offset.
     * @param int $size Number of bytes the operation needs.
     * @param int $length Actual buffer length.
     */
    public static function forRange(string $operation, int $offset, int $size, int $length): self
    {
        return new self(sprintf(
            'The value of "offset" is out of range. %s at offset %d needs %d byte(s), but the buffer is %d byte(s) long.',
            $operation,
            $offset,
            $size,
            $length
        ));
    }
}
