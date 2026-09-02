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

use RuntimeException;

/**
 * Base class for every exception this library throws.
 *
 * Extends RuntimeException, so existing `catch (\Exception $e)` blocks keep
 * working while callers that want to be specific can catch this instead.
 *
 * @psalm-api
 */
class BufferException extends RuntimeException
{
}
