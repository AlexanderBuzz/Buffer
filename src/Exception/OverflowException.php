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
 * Thrown when a value cannot be represented in the requested target type,
 * for example a buffer larger than 8 bytes converted through toInt().
 *
 * @psalm-api
 */
class OverflowException extends BufferException
{
}
