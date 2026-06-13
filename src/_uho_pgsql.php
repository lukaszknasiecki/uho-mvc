<?php

namespace Huncwot\UhoFramework;

/**
 * PostgreSQL driver wrapping PDO.
 * Exposes the same interface as _uho_mysqli so it can be used
 * as a drop-in by _uho_orm_pgsql_prepared.
 */
class _uho_pgsql
{
    private \PDO $pdo;
    private int $affectedRows = 0;
    private array $query_log = [];
    private bool $halt_on_error = true;

    /**
     * Opens a PDO connection to a PostgreSQL database.
     */
    public function init(string $host, string $user, string $pass, string $name, int $port = 5432): bool
    {
        try {
            $dsn = "pgsql:host={$host};port={$port};dbname={$name}";
            $this->pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
            return true;
        } catch (\PDOException $e) {
            exit('PostgreSQL Connection Error: ' . $e->getMessage());
        }
    }

    /** Returns the underlying PDO instance (used by _uho_orm_pgsql_prepared::sqlSafe). */
    public function getBase(): \PDO
    {
        return $this->pdo;
    }

    public function getLastQueryLog(): ?string
    {
        return !empty($this->query_log) ? end($this->query_log) : null;
    }

    public function addQueryLog(string $query): void
    {
        $this->query_log[] = $query;
    }

    public function getQueryLogs(): array
    {
        return $this->query_log;
    }

    /**
     * Runs a read-only query and returns an associative array of rows.
     * Signature matches _uho_mysqli::query().
     */
    public function query(string $query, bool $single = false, bool $stripslashes = true, ?string $key = null, bool $force_sql_cache = false): array|false
    {
        $this->query_log[] = $query;
        try {
            $stmt = $this->pdo->query($query);
            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if ($key) {
                $keyed = [];
                foreach ($result as $v) $keyed[$v[$key]] = $v;
                return $keyed;
            }
            if ($single && isset($result[0])) return $result[0];
            return $result;
        } catch (\PDOException $e) {
            if ($this->halt_on_error) exit('PostgreSQL error: ' . $e->getMessage() . '<br>Query: ' . $query);
            return false;
        }
    }

    /**
     * Runs a write query and returns true on success.
     * Signature matches _uho_mysqli::queryOut().
     */
    public function queryOut(string $query): bool
    {
        $this->query_log[] = $query;
        try {
            $this->affectedRows = $this->pdo->exec($query);
            return true;
        } catch (\PDOException $e) {
            if ($this->halt_on_error) exit('PostgreSQL error: ' . $e->getMessage() . '<br>Query: ' . $query);
            return false;
        }
    }

    public function multiQueryOut(string $query): bool
    {
        return $this->queryOut($query);
    }

    /** Returns last inserted row ID (requires the table to have a SEQUENCE). */
    public function insert_id(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    public function affected_rows(): int
    {
        return $this->affectedRows;
    }

    /**
     * Returns a safely escaped string value (without surrounding quotes).
     * Used by _uho_orm_pgsql_prepared::sqlSafe().
     */
    public function safe(string $s): string
    {
        $quoted = $this->pdo->quote($s);
        // PDO::quote() wraps in single-quotes; strip them so callers can add their own.
        return substr($quoted, 1, -1);
    }

    public function setHaltOnError(bool $halt): void
    {
        $this->halt_on_error = $halt;
    }

    // -------------------------------------------------------------------------
    // Prepared-statement helpers
    // -------------------------------------------------------------------------

    /**
     * Prepared INSERT.
     * $params: array of [phpType, value, fieldName] tuples  (phpType is ignored for PDO).
     * Returns the new row's id (via RETURNING id) or false on failure.
     */
    public function insertPrepared(string $table, array $params): int|false
    {
        if (empty($params)) return false;

        $fields = [];
        $placeholders = [];
        $values = [];
        $i = 1;
        foreach ($params as $p) {
            $fields[]       = '"' . $p[2] . '"';
            $placeholders[] = '$' . $i++;
            $values[]       = $p[1];
        }

        $query = 'INSERT INTO "' . $table . '" (' . implode(',', $fields) . ') VALUES (' . implode(',', $placeholders) . ') RETURNING id';
        $this->query_log[] = $query;

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($values);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ? (int) $row['id'] : (int) $this->pdo->lastInsertId();
        } catch (\PDOException $e) {
            if ($this->halt_on_error) exit('PostgreSQL insertPrepared error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Prepared INSERT for multiple rows.
     * $fields: field names; $records: array of [phpType, value] tuples per row.
     */
    public function insertMultiplePrepared(string $table, array $fields, array $records): int|false
    {
        if (empty($fields) || empty($records)) return false;

        $fieldList    = array_map(fn($f) => '"' . $f . '"', $fields);
        $placeholders = [];
        $values       = [];
        $i            = 1;

        foreach ($records as $record) {
            $row = [];
            foreach ($record as $p) {
                $row[]    = '$' . $i++;
                $values[] = $p[1];
            }
            $placeholders[] = '(' . implode(',', $row) . ')';
        }

        $query = 'INSERT INTO "' . $table . '" (' . implode(',', $fieldList) . ') VALUES ' . implode(',', $placeholders);
        $this->query_log[] = $query;

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($values);
            return (int) $this->pdo->lastInsertId();
        } catch (\PDOException $e) {
            if ($this->halt_on_error) exit('PostgreSQL insertMultiplePrepared error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Prepared UPDATE.
     * $setParams / $whereParams: [phpType, value, fieldName] tuples.
     * $whereClause: optional raw WHERE clause string (no binding).
     */
    public function updatePrepared(string $table, array $setParams, array $whereParams = [], string $whereClause = ''): bool
    {
        if (empty($setParams)) return false;

        $setFields = [];
        $values    = [];
        $i         = 1;

        foreach ($setParams as $p) {
            $setFields[] = '"' . $p[2] . '"=$' . $i++;
            $values[]    = $p[1];
        }

        $query = 'UPDATE "' . $table . '" SET ' . implode(',', $setFields);

        if ($whereClause) {
            $query .= ' ' . $whereClause;
            foreach ($whereParams as $p) { $values[] = $p[1]; }
        } elseif (!empty($whereParams)) {
            $whereFields = [];
            foreach ($whereParams as $p) {
                $whereFields[] = '"' . $p[2] . '"=$' . $i++;
                $values[]      = $p[1];
            }
            $query .= ' WHERE ' . implode(' AND ', $whereFields);
        }

        $this->query_log[] = $query;

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($values);
            $this->affectedRows = $stmt->rowCount();
            return true;
        } catch (\PDOException $e) {
            if ($this->halt_on_error) exit('PostgreSQL updatePrepared error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Prepared DELETE.
     */
    public function deletePrepared(string $table, array $whereParams = [], string $whereClause = ''): bool
    {
        $values = [];
        $query  = 'DELETE FROM "' . $table . '"';
        $i      = 1;

        if ($whereClause) {
            $query .= ' ' . $whereClause;
            foreach ($whereParams as $p) { $values[] = $p[1]; }
        } elseif (!empty($whereParams)) {
            $whereFields = [];
            foreach ($whereParams as $p) {
                $whereFields[] = '"' . $p[2] . '"=$' . $i++;
                $values[]      = $p[1];
            }
            $query .= ' WHERE ' . implode(' AND ', $whereFields);
        }

        $this->query_log[] = $query;

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($values);
            $this->affectedRows = $stmt->rowCount();
            return true;
        } catch (\PDOException $e) {
            if ($this->halt_on_error) exit('PostgreSQL deletePrepared error: ' . $e->getMessage());
            return false;
        }
    }
}
