<?php

declare(strict_types=1);

namespace SmartHeart\Auth;

use RuntimeException;

/** A token failed verification. The message is for logs only; clients always get a plain 401. */
final class InvalidToken extends RuntimeException
{
}
