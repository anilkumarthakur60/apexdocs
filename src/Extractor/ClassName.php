<?php

declare(strict_types=1);

namespace ApexDocs\Extractor;

/**
 * Class-name helpers.
 *
 * Deliberately a class rather than a global helper function: a conditionally
 * declared `class_basename()` polyfill only exists once its defining file has
 * been autoloaded, which made call sites order-dependent.
 */
final class ClassName
{
    private function __construct() {}

    /** "App\Http\Resources\UserResource" → "UserResource" */
    public static function short(string $class): string
    {
        $class = trim($class, '\\');
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
