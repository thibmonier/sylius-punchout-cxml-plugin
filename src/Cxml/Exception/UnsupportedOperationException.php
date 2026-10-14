<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Cxml\Exception;

/** A well-formed request asking for something this supplier does not implement (cXML status 450). */
final class UnsupportedOperationException extends \RuntimeException
{
}
