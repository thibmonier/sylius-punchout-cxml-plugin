<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Cxml;

/**
 * cXML status codes used in response headers.
 */
final class CxmlStatus
{
    public const OK = 200;

    public const BAD_REQUEST = 400;

    public const UNAUTHORIZED = 401;

    public const FORBIDDEN = 403;

    public const NOT_ACCEPTABLE = 406;

    public const OPERATION_FAILED = 450;

    /** @return array{code: int, text: string} */
    public static function ok(): array
    {
        return ['code' => self::OK, 'text' => 'OK'];
    }

    /** @return array{code: int, text: string} */
    public static function unauthorized(): array
    {
        return ['code' => self::UNAUTHORIZED, 'text' => 'Unauthorized'];
    }

    /** @return array{code: int, text: string} */
    public static function forbidden(): array
    {
        return ['code' => self::FORBIDDEN, 'text' => 'Forbidden'];
    }

    /** @return array{code: int, text: string} */
    public static function notAcceptable(): array
    {
        return ['code' => self::NOT_ACCEPTABLE, 'text' => 'Not Acceptable'];
    }

    /** @return array{code: int, text: string} */
    public static function operationFailed(string $text = 'Operation failed'): array
    {
        return ['code' => self::OPERATION_FAILED, 'text' => $text];
    }
}
