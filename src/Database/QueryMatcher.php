<?php

namespace RaceLab\LaravelRaceLab\Database;

use RaceLab\LaravelRaceLab\Enums\QueryOperation;
use RaceLab\LaravelRaceLab\Enums\QueryTiming;

final class QueryMatcher
{
    /** @param array<string,mixed> $checkpoint */
    public function matches(
        array $checkpoint,
        QueryTiming $timing,
        string $sql,
        string $connection,
    ): bool {
        if (($checkpoint['type'] ?? null) !== 'query') return false;
        if (($checkpoint['timing'] ?? null) !== $timing->value) return false;

        if (! empty($checkpoint['connection']) && $checkpoint['connection'] !== $connection) return false;

        if (! empty($checkpoint['operation'])) {
            $actual = QueryOperation::detect($sql)->value;
            if ($actual !== strtolower((string) $checkpoint['operation'])) return false;
        }

        if (! empty($checkpoint['table']) && ! $this->matchesTable($sql, (string) $checkpoint['table'])) return false;

        foreach ((array) ($checkpoint['contains'] ?? []) as $fragment) {
            if (! str_contains(strtolower($sql), strtolower((string) $fragment))) return false;
        }

        if (! empty($checkpoint['sql_regex']) && preg_match((string) $checkpoint['sql_regex'], $sql) !== 1) return false;

        return true;
    }

    private function matchesTable(string $sql, string $table): bool
    {
        $quoted = preg_quote(strtolower($table), '/');
        $normalized = strtolower($sql);

        $identifier = '(?:`|"|\[)?'.$quoted.'(?:`|"|\])?';
        $patterns = [
            '/\bfrom\s+'.$identifier.'\b/i',
            '/\bjoin\s+'.$identifier.'\b/i',
            '/\bupdate\s+'.$identifier.'\b/i',
            '/\binsert\s+into\s+'.$identifier.'\b/i',
            '/\bdelete\s+from\s+'.$identifier.'\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) return true;
        }

        return false;
    }
}
