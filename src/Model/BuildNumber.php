<?php

declare(strict_types=1);

namespace Ordinal\Model;

use Ordinal\Service\AccountException;

/** Parses canonical uint32 form input without fractional or signed coercion. */
final class BuildNumber
{
    /** Defines the inclusive uint32 upper bound. */
    public const int MAX_VALUE = 4294967295;

    /**
     * Requires a canonical decimal whole number in the agreed range.
     *
     * @param string $value
     * @return int
     */
    public static function parse(string $value): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1 || $number === false || $number > self::MAX_VALUE) {
            throw new AccountException('Use a whole build number from 0 through 4294967295.', 400);
        }
        return $number;
    }
}
