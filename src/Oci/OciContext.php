<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Oci;

/**
 * What an OCI entry keeps for the return (US-012): the standard `returntarget` and
 * `http_content_charset` parameters and the `~` fields (`~OkCode`, `~TARGET`, `~CALLER`…), which are
 * not in the OCI specification but are sent back as received. Names and values are checked: they end
 * up in the attributes of the return form.
 */
final class OciContext
{
    public const RETURN_TARGET = 'returntarget';

    public const CHARSET = 'http_content_charset';

    private const MAX_TILDE_FIELDS = 20;

    private const FRAME_NAME = '/^[A-Za-z0-9_-]{1,64}$/';

    private const CHARSET_NAME = '/^[A-Za-z0-9_.:-]{1,40}$/';

    private const TILDE_NAME = '/^~[A-Za-z0-9_-]{1,63}$/';

    /**
     * @param array<array-key, mixed> $parameters the entry parameters
     *
     * @return array<string, string>
     */
    public static function fromParameters(array $parameters): array
    {
        $context = [];
        foreach ([self::RETURN_TARGET => self::FRAME_NAME, self::CHARSET => self::CHARSET_NAME] as $name => $pattern) {
            $value = $parameters[$name] ?? null;
            if (is_string($value) && 1 === preg_match($pattern, $value)) {
                $context[$name] = $value;
            }
        }
        foreach ($parameters as $name => $value) {
            if (count($context) >= self::MAX_TILDE_FIELDS + 2) {
                break;
            }
            if (is_string($name) && is_string($value) && 1 === preg_match(self::TILDE_NAME, $name) && mb_strlen($value) <= 255) {
                $context[$name] = $value;
            }
        }
        // ~TARGET decides the frame when there is no returntarget: a frame name only.
        if (isset($context['~TARGET']) && 1 !== preg_match(self::FRAME_NAME, $context['~TARGET'])) {
            unset($context['~TARGET']);
        }

        return $context;
    }
}
