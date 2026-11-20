<?php
declare(strict_types=1);

namespace SqlRewriter\Analyzer;

/**
 * Built-in PHP functions whose result cannot carry SQL-breaking text,
 * regardless of their inputs (numeric/boolean coercion, formatting).
 *
 * Note: string shapers such as trim(), strtolower(), htmlspecialchars(),
 * preg_replace() are NOT here — quotes and comparison operators survive
 * all of them, so tainted input stays tainted.
 */
final class Sanitizers
{
    private const SAFE = [
        'intval'      => true,
        'integerval'  => true,
        'floatval'    => true,
        'doubleval'   => true,
        'boolval'     => true,
        'abs'         => true,
        'ceil'        => true,
        'floor'       => true,
        'round'       => true,
        'max'         => true,
        'min'         => true,
        'number_format' => true,
        'strlen'      => true,
        'mb_strlen'   => true,
        'count'       => true,
        'strtotime'   => true,   // returns int|false
        'time'        => true,
        'mktime'      => true,
        'date'        => true,
        'microtime'   => true,
        'crc32'       => true,
        'ip2long'     => true,
        'json_encode' => true,   // quotes are escaped inside the JSON text
    ];

    public static function isSanitizer(string $functionName): bool
    {
        return isset(self::SAFE[strtolower($functionName)]);
    }
}
