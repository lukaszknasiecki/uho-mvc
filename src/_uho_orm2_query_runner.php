<?php

declare(strict_types=1);

namespace Huncwot\UhoFramework;

/**
 * The only place that talks to the SQL driver.
 *
 * Read queries, write queries, prepared writes, value escaping and the ':lang'
 * rewriting all live here, so the rest of the ORM never touches the handle.
 */
class _uho_orm2_query_runner
{
    public function __construct(private _uho_orm2_context $context) {}

    // -------------------------------------------------------------------------
    // Reads
    // -------------------------------------------------------------------------

    /**
     * Runs a read-only query.
     *
     * @param bool $single       return the first row only
     * @param bool $stripslashes driver-level unescaping of the result
     * @param string|null $key   index the result by this column
     * @param string|null $fieldOnly reduce every row to this single column
     */
    public function query(
        string $query,
        bool $single = false,
        bool $stripslashes = true,
        ?string $key = null,
        ?string $fieldOnly = null,
        bool $forceSqlCache = false
    ): mixed {
        $sql = $this->context->getSql();
        if (!$sql) return null;

        $result = $sql->query($this->processLangQuery($query), $single, $stripslashes, $key, $forceSqlCache);

        if ($fieldOnly !== null && is_array($result))
            foreach ($result as $k => $row)
                if (is_array($row)) $result[$k] = $row[$fieldOnly] ?? null;

        return $result;
    }

    // -------------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------------

    /**
     * Runs a write query, logging the statement when the driver rejects it.
     */
    public function queryOut(string $query): mixed
    {
        $sql = $this->context->getSql();
        if (!$sql) $this->context->halt('_uho_orm2::No SQL defined::queryOut');

        $result = $sql->queryOut($query);
        if (!$result) $this->context->addError($query);

        return $result;
    }

    public function multiQueryOut(string $query): mixed
    {
        $sql = $this->context->getSql();
        if (!$sql) $this->context->halt('_uho_orm2::No SQL defined::multiQueryOut');

        return $sql->multiQueryOut($query);
    }

    /**
     * Prepared UPDATE, delegated to the driver.
     *
     * @param array<int, array{0: string, 1: mixed, 2: string}> $setParams
     * @param array<int, array{0: string, 1: mixed, 2: string}> $whereParams
     */
    public function updatePrepared(string $table, array $setParams, array $whereParams = [], string $whereClause = ''): mixed
    {
        $sql = $this->context->getSql();
        if (!$sql) $this->context->halt('_uho_orm2::No SQL defined::updatePrepared');

        $result = $sql->updatePrepared($table, $setParams, $whereParams, $whereClause);
        if (!$result) $this->context->addError('updatePrepared:: ' . $table);

        return $result;
    }

    public function getInsertId(): mixed
    {
        $sql = $this->context->getSql();

        return $sql?->insert_id();
    }

    public function getAffectedRows(): mixed
    {
        $sql = $this->context->getSql();

        return $sql?->affected_rows();
    }

    // -------------------------------------------------------------------------
    // Escaping
    // -------------------------------------------------------------------------

    /**
     * Driver-level escaping of a single value.
     *
     * Note this escapes VALUES only - it does not make an identifier safe,
     * because the backquote is not on the driver's escape list. Identifiers go
     * through _uho_orm2_sql_guard.
     */
    public function sqlSafe(mixed $value): mixed
    {
        if ($value === '0') return '0';

        $sql = $this->context->getSql();
        if (!$sql && $this->context->isTest()) return $value;
        if (!$sql) $this->context->halt('sqlSafe::sql-not-defined');

        if ($value && !is_array($value)) return $sql->getBase()->real_escape_string((string) $value);

        return null;
    }

    public function checkConnection(?string $message = null): void
    {
        if (!$this->context->hasSql()) $this->context->halt('_uho_orm2::No SQL defined::' . $message);
    }

    // -------------------------------------------------------------------------
    // Language rewriting
    // -------------------------------------------------------------------------

    /**
     * Rewrites ':lang' markers in the SELECT part of a query into the current
     * language column (or into one column per language when no current
     * language is set).
     */
    public function processLangQuery(string $query): string
    {
        if (!strpos($query, ':lang')) return $query;

        $parts   = explode('FROM', $query);
        $select  = $parts[0];
        $langAdd = $this->context->getLangAdd();
        $langs   = $this->context->getLanguages();

        while ($i = strpos($select, ':lang')) {
            $j = $i;
            while ($j >= 0 && $select[$j] != ' ' && $select[$j] != ',') $j--;

            $field     = substr($select, $j + 1, $i - $j - 1);
            $fieldOnly = explode('.', $field);
            $fieldOnly = array_pop($fieldOnly);

            if ($langAdd) {
                $field = $field . $langAdd;
                $next  = strtolower(trim(substr($select, $i + 5)));
                if (substr($next, 0, 2) != 'as') $field .= ' AS `' . $fieldOnly . '`';
            } elseif ($langs) {
                $expanded = [];
                foreach ($langs as $lang) $expanded[] = $field . $lang['lang_add'];
                $field = implode(', ', $expanded);
            }

            $select = substr($select, 0, $j + 1) . $field . substr($select, $i + 5);
        }

        $parts[0] = $select;

        return implode('FROM', $parts);
    }

    /**
     * Wraps bare column names in backquotes, leaving expressions
     * (COUNT(*), AVG(`x`) AS average, ...) and ':lang' markers untouched.
     *
     * @param array<int, string> $fields
     * @return array<int, string>
     */
    public function quoteFieldList(array $fields): array
    {
        foreach ($fields as $k => $field)
            if (!strpos($field, ':lang') && !str_contains($field, '('))
                $fields[$k] = '`' . $field . '`';

        return $fields;
    }
}
