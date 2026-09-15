<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Security;

use Inclitoleo\Mysql\Exception\InvalidIdentifierException;

final class IdentifierValidator
{
    public const PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    public static function isValid(string $identifier): bool
    {
        return $identifier !== '' && preg_match(self::PATTERN, $identifier) === 1;
    }

    public static function requireValid(string $identifier): void
    {
        if (!self::isValid($identifier)) {
            throw new InvalidIdentifierException(
                'Invalid SQL identifier: ' . $identifier,
                $identifier,
                self::reason($identifier),
            );
        }
    }

    public static function reason(string $identifier): string
    {
        if ($identifier === '') {
            return 'identifier must not be empty';
        }
        if (str_contains($identifier, ';')) {
            return 'identifier contains a semicolon';
        }
        if (preg_match('/\s/', $identifier) === 1) {
            return 'identifier contains whitespace';
        }

        return 'identifier must match ' . self::PATTERN;
    }
}
