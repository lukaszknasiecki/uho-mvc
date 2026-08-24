<?php

declare(strict_types=1);

namespace Huncwot\UhoFramework;

/**
 * Everything that turns caller-supplied text into an SQL identifier or clause.
 *
 * Values are escaped by the driver; identifiers cannot be, so they are matched
 * against the schema column list (or a strict charset) and quoted here. All of
 * it lives in one class so there is a single place to audit.
 */
class _uho_orm2_sql_guard
{
    /** Column name, optionally with the ':lang' marker used by the schemas */
    public const COLUMN_PATTERN = '/^[A-Za-z0-9_]+(:lang)?$/';

    /**
     * Functions a filter may wrap a column in. Kept separate from
     * AGGREGATE_FUNCTIONS because a filter needs the case-folding ones and an
     * aggregate does not.
     */
    public const FILTER_FUNCTIONS = [
        'ABS',
        'CHAR_LENGTH',
        'DATE',
        'DAY',
        'HOUR',
        'LENGTH',
        'LOWER',
        'MINUTE',
        'MONTH',
        'ROUND',
        'TRIM',
        'UNIX_TIMESTAMP',
        'UPPER',
        'YEAR',
    ];

    /** Functions allowed inside AVG() for count.type = average */
    public const AGGREGATE_FUNCTIONS = [
        'ABS',
        'CEIL',
        'CEILING',
        'CHAR_LENGTH',
        'DAY',
        'FLOOR',
        'HOUR',
        'LENGTH',
        'MINUTE',
        'MONTH',
        'ROUND',
        'UNIX_TIMESTAMP',
        'YEAR',
    ];

    public function __construct(
        private _uho_orm2_context $context,
        private ?_uho_orm2_query_runner $runner = null
    ) {}

    /**
     * Column names a query may sort, group or aggregate by: everything declared
     * in the schema, plus the implicit 'id'.
     *
     * @return array<int, string>
     */
    public function allowedColumns(array $schema): array
    {
        $columns = ['id'];

        foreach ($schema['fields'] ?? [] as $field)
            if (!empty($field['field'])) $columns[] = (string) $field['field'];

        return $columns;
    }

    /**
     * Returns a backquoted column name if it belongs to the schema, null otherwise.
     * ':lang' names are resolved to the current language column, the way
     * processLangQuery() does it for the SELECT part.
     *
     * @param array<int, string> $allowedColumns
     */
    public function fieldName(mixed $input, array $allowedColumns): ?string
    {
        if (!is_string($input)) return null;

        $column = trim(trim($input), '`');
        if (!in_array($column, $allowedColumns, true)) return null;

        return '`' . $this->resolveLangColumn($column) . '`';
    }

    /**
     * Column name for a filter: schema columns win, anything else has to look
     * like a column name. Values are quoted separately, so this is the only
     * thing standing between a request and the identifier part of the query.
     */
    public function filterFieldName(string $input, array $allowedColumns): ?string
    {
        $column = trim(trim($input), '`');

        if (in_array($column, $allowedColumns, true)) return $column;
        if (preg_match(self::COLUMN_PATTERN, $column)) return $column;

        return null;
    }

    /**
     * Sanitizes a GROUP BY clause. Returns null if any of the listed columns is
     * not allowed - dropping just the offending one would silently change the
     * result set.
     *
     * @param array<int, string> $allowedColumns
     */
    public function groupBy(string $input, array $allowedColumns): ?string
    {
        $result = [];

        foreach (explode(',', $input) as $clause) {
            if (trim($clause) === '') continue;

            $column = $this->fieldName($clause, $allowedColumns);
            if ($column === null) return null;

            $result[] = $column;
        }

        return $result ? implode(', ', $result) : null;
    }

    /**
     * Aggregate function name for count.type = average, uppercased, or null.
     */
    public function aggregateFunction(mixed $input): ?string
    {
        return $this->allowedFunction($input, self::AGGREGATE_FUNCTIONS);
    }

    /**
     * Function name a filter may wrap its column in, uppercased, or null.
     * A filter carries this in its 'function' key, so it is caller input and
     * has to be matched against a list like every other piece of syntax.
     */
    public function filterFunction(mixed $input): ?string
    {
        return $this->allowedFunction($input, self::FILTER_FUNCTIONS);
    }

    /**
     * @param array<int, string> $allowed
     */
    private function allowedFunction(mixed $input, array $allowed): ?string
    {
        if (!is_string($input)) return null;

        $function = strtoupper(trim($input));

        return in_array($function, $allowed, true) ? $function : null;
    }

    /**
     * Sanitizes an ORDER BY input string against a strict list of allowed columns.
     *
     * Supports:
     * - simple column sorting: "price ASC", "name, created_at DESC"
     * - custom order via FIELD(): "FIELD(status, 'active', 'pending')"
     * - random sorting: "RAND()" / "RAND(seed)"
     *
     * @param array<int, string> $allowedColumns
     */
    public function orderBy(string $input, array $allowedColumns, string $default = 'id ASC'): string
    {
        $input = trim($input);
        if ($input === '') return $default;

        // split top-level clauses by comma, ignoring commas inside parentheses
        $clauses = preg_split('/,\s*(?![^(]*\))/', $input) ?: [];
        $sanitized = [];

        foreach ($clauses as $clause) {
            $clause = trim($clause);
            if ($clause === '') continue;

            $rand = $this->orderByRand($clause);
            if ($rand !== null) {
                $sanitized[] = $rand;
                continue;
            }

            $field = $this->orderByField($clause, $allowedColumns);
            if ($field !== null) {
                $sanitized[] = $field;
                continue;
            }

            $column = $this->orderByColumn($clause, $allowedColumns);
            if ($column !== null) $sanitized[] = $column;
        }

        return $sanitized ? implode(', ', $sanitized) : $default;
    }

    /**
     * ORDER BY ... ASC/DESC for one plain column.
     */
    private function orderByColumn(string $clause, array $allowedColumns): ?string
    {
        $parts     = preg_split('/\s+/', $clause) ?: [];
        $column    = $parts[0] ?? '';
        $direction = strtoupper($parts[1] ?? 'ASC');

        if (!in_array($column, $allowedColumns, true)) return null;
        if (!in_array($direction, ['ASC', 'DESC'], true)) $direction = 'ASC';

        return '`' . $column . '` ' . $direction;
    }

    /**
     * RAND() / RAND(seed)
     */
    private function orderByRand(string $clause): ?string
    {
        if (!preg_match('/^RAND\s*\(\s*(.*?)\s*\)$/i', $clause, $matches)) return null;

        $seed = trim($matches[1]);
        if ($seed === '') return 'RAND()';

        $seed = trim($seed, "'\"");

        return is_numeric($seed)
            ? 'RAND(' . $seed . ')'
            : "RAND('" . $this->quoteLiteral($seed) . "')";
    }

    /**
     * FIELD(column, 'a', 'b', ...) with an optional "= 0" suffix.
     */
    private function orderByField(string $clause, array $allowedColumns): ?string
    {
        if (!preg_match('/^FIELD\s*\(\s*([a-zA-Z0-9_]+)\s*,\s*(.+)\s*\)(\s*=\s*0)?$/i', $clause, $matches))
            return null;

        $column     = $matches[1];
        $equalsZero = empty($matches[3]) ? '' : ' = 0';

        if (!in_array($column, $allowedColumns, true)) return null;

        $values = array_map(
            function (string $value): string {
                $value = trim(trim($value), "'\"");
                return is_numeric($value) ? $value : "'" . $this->quoteLiteral($value) . "'";
            },
            explode(',', $matches[2])
        );

        return 'FIELD(`' . $column . '`, ' . implode(',', $values) . ')' . $equalsZero;
    }

    /**
     * Resolves the ':lang' marker to the current language column.
     */
    public function resolveLangColumn(string $column): string
    {
        if (!str_contains($column, ':lang')) return $column;

        return explode(':lang', $column)[0] . (string) $this->context->getLangAdd();
    }

    /**
     * Escaping for literals this class builds itself (ORDER BY seeds and
     * FIELD() values).
     *
     * The driver's escape knows the connection charset, so it is used whenever
     * a handle is available; addslashes() remains only as the no-database
     * fallback, where nothing is going to be executed anyway.
     */
    private function quoteLiteral(string $value): string
    {
        if ($this->runner !== null && $this->context->hasSql()) {
            $escaped = $this->runner->sqlSafe($value);
            if (is_string($escaped)) return $escaped;
            if ($escaped === null) return '';
        }

        return addslashes($value);
    }
}
