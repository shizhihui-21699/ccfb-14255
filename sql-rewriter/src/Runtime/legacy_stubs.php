<?php
declare(strict_types=1);

/**
 * Compatibility stubs for legacy helper functions that rewritten code may
 * still reference. These are GLOBAL functions, matching the unqualified
 * call sites in the legacy code, and preserve call-site behavior while the
 * dangerous string-concatenation path has been removed by the rewriter.
 */

namespace {
    if (!function_exists('legacy_escape')) {
        /**
         * Kept only so rewritten expressions that still call legacy_escape()
         * resolve. In rewritten code its result is always bound as a
         * parameter, so manual escaping is no longer load-bearing.
         */
        function legacy_escape(string $value): string
        {
            return $value;
        }
    }

    if (!function_exists('mysql_real_escape')) {
        function mysql_real_escape(string $value): string
        {
            return $value;
        }
    }

    if (!function_exists('mysqli_real_escape')) {
        function mysqli_real_escape(mixed $link, string $value): string
        {
            return $value;
        }
    }

    if (!function_exists('legacy_int_list')) {
        /**
         * Returns the input array; rewritten IN lists go through SqlGuard::inList().
         */
        function legacy_int_list(array $values): array
        {
            return array_values($values);
        }
    }
}
