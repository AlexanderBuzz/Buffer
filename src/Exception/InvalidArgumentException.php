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
 * Thrown when an argument is of an unsupported type or shape, for example an
 * unsupported source passed to Buffer::from().
 */
class InvalidArgumentException extends BufferException
{
}
