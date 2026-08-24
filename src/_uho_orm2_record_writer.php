<?php

declare(strict_types=1);

namespace Huncwot\UhoFramework;

/**
 * Turns a record array into the SET / VALUES part of a write query.
 *
 * One place converts a schema type into its stored representation - the
 * digit-padded element lists, the JSON columns, the UTC timestamps, the
 * encrypted fields - so INSERT, UPDATE and the prepared-statement path
 * cannot drift apart.
 *
 * Media types (image, video, file, audio, media) and virtual fields are
 * never written: they live on disk or are computed on read.
 */
class _uho_orm2_record_writer
{
    /** field types that have no column of their own */
    private const SKIP_TYPES = ['image', 'video', 'file', 'audio', 'virtual', 'media'];

    public function __construct(
        private _uho_orm2_context $context,
        private _uho_orm2_query_runner $runner
    ) {}

    // -------------------------------------------------------------------------
    // Single record
    // -------------------------------------------------------------------------

    /**
     * Builds '`field`="value"' pairs joined by $join, ready for SET or WHERE.
     * Returns an empty array when nothing is left to write.
     */
    public function buildQuery(array $schema, array $data, string $join = ','): array|string|null
    {
        foreach ($data as $key => $value) {
            $field = _uho_fx::array_filter($schema['fields'], 'field', $key, ['first' => true]);

            if ($field && $this->isReadonly($field)) {
                unset($data[$key]);
                continue;
            }

            if ($key === 'id') {
                $data[$key] = $key . '="' . $this->runner->sqlSafe($value) . '"';
                continue;
            }

            if ($field && in_array($field['type'] ?? '', self::SKIP_TYPES, true)) {
                unset($data[$key]);
                continue;
            }

            if (!$field) {
                // a field absent from the schema is written only when a schema
                // filter already names it - otherwise it is silently dropped
                if (isset($schema['filters'][$key]))
                    $data[$key] = '`' . $key . '`="' . $this->runner->sqlSafe($value) . '"';
                else unset($data[$key]);

                continue;
            }

            [$value, $quoteAsIs] = $this->encodeValue($field, $value);

            $data[$key] = $this->assign($key, $value, $field, $quoteAsIs);
        }

        if ($data) $data = implode($join, $data);

        return $data;
    }

    /**
     * Converts a value to its stored form.
     *
     * @return array{0: mixed, 1: bool} the value, and whether it is already
     *                                  safe to interpolate without escaping
     */
    private function encodeValue(array $field, mixed $value): array
    {
        switch ($field['type'] ?? '') {

            case 'checkboxes':
            case 'elements':
                $value = $this->encodeElementList($field, $value);
                break;

            case 'boolean':
                $value = ($value === true || $value === 'on' || $value === 1 || $value === '1') ? 1 : 0;
                break;

            case 'timestamp':
                $value = (new \DateTimeImmutable((string) $value, new \DateTimeZone(date_default_timezone_get())))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s');
                break;

            case 'float':
                return [floatval($value), true];

            case 'integer':
                return [intval($value), true];

            case 'order':
                $value = intval($value);
                break;

            case 'json':
            case 'blocks':
                if (is_array($value)) $value = json_encode($value);
                break;

            case 'select':
                $digits = $this->outputDigits($field, 0);
                if ($digits) $value = _uho_fx::dozeruj($value, $digits);
                elseif (($field['settings']['output'] ?? null) === 'string') break;
                elseif (is_numeric($value)) return [$value, true];
                break;

            case 'table':
                if (($field['settings']['format'] ?? null) === 'object') {
                    $pairs = $value;
                    $value = [];
                    foreach ($pairs as $pair) $value[$pair[0]] = $pair[1];
                }
                $value = json_encode($value);
                break;
        }

        return [$value, false];
    }

    /**
     * Element/checkbox lists are stored as one comma-separated string, each id
     * left-padded so that a LIKE '%00000012%' filter cannot match id 120.
     */
    private function encodeElementList(array $field, mixed $value): mixed
    {
        if (!is_array($value)) return $value;

        $digits = $this->outputDigits($field, 8);

        foreach ($value as $k => $v)
            if ($digits) $value[$k] = _uho_fx::dozeruj($v, $digits);

        return implode(',', $value);
    }

    /**
     * A field the schema marks settings.readonly is never written, whatever the
     * payload says. This is the schema-level half of the write policy; the
     * call-site half is params['fields'] in post()/put().
     */
    private function isReadonly(array $field): bool
    {
        return !empty($field['settings']['readonly']);
    }

    /**
     * settings.output = '4digits'|'6digits'|'8digits'|'string'
     */
    private function outputDigits(array $field, int $default): int
    {
        $output = $field['settings']['output'] ?? null;

        if ($output === null) return $default;
        if ($output === 'string') return 0;

        return match ($output) {
            '4digits' => 4,
            '6digits' => 6,
            '8digits' => 8,
            default   => $default,
        };
    }

    /**
     * Formats one '`field`=value' pair, encrypting it when the schema asks.
     */
    private function assign(string $key, mixed $value, array $field, bool $quoteAsIs): string
    {
        $hash = $field['settings']['hash'] ?? null;

        if ($hash !== null) {
            $encrypted = $hash[0] === '~'
                ? _uho_fx::encrypt($value, $this->context->getKeys(), substr($hash, 1), true)
                : _uho_fx::encrypt($value, $this->context->getKeys(), $hash);

            return '`' . $key . '`="' . $encrypted . '"';
        }

        if ($value === 0)    return '`' . $key . '`=0';
        if ($value === null) return '`' . $key . '`=NULL';

        // numeric values were cast above and carry no quotes to escape
        if ($quoteAsIs) return '`' . $key . '`="' . $value . '"';

        return '`' . $key . '`="' . $this->runner->sqlSafe($value) . '"';
    }

    // -------------------------------------------------------------------------
    // Multiple records
    // -------------------------------------------------------------------------

    /**
     * Builds the '(fields) VALUES (...), (...)' tail of a multi-row INSERT,
     * or the raw ['fields' =>, 'values' =>] structure behind it.
     *
     * The column list is settled first - every field at least one record
     * carries - and only then are the rows emitted, so a record missing a
     * field in the middle writes an empty value into that column instead of
     * shifting all the following ones.
     */
    public function buildQueryMultiple(array $schema, array $data, string $output = 'query'): array|string
    {
        $fields = $schema['fields'];
        $hasId  = false;

        foreach ($fields as $k => $field) {
            if (empty($field['field'])
                || in_array($field['type'] ?? '', self::SKIP_TYPES, true)
                || $this->isReadonly($field)) unset($fields[$k]);
            elseif ($field['field'] === 'id') $hasId = true;
        }

        if (!$hasId) $fields[] = ['field' => 'id'];

        // columns: those any record actually carries, in schema order
        $columns = [];
        foreach ($fields as $field)
            foreach ($data as $record)
                if (array_key_exists($field['field'], $record)) {
                    $columns[] = $field;
                    break;
                }

        $result = ['fields' => [], 'values' => []];
        foreach ($columns as $field) $result['fields'][] = $field['field'];

        foreach ($data as $row => $record) {
            $result['values'][$row] = [];

            foreach ($columns as $field) {
                $value = $record[$field['field']] ?? '';
                if ($value === null) $value = '';

                if (isset($field['settings']['hash']))
                    $value = _uho_fx::encrypt($value, $this->context->getKeys(), $field['settings']['hash']);

                $result['values'][$row][] = $this->encodeValueMultiple($field, $value);
            }
        }

        if ($output !== 'query') return $result;

        foreach ($result['values'] as $row => $record) {
            foreach ($record as $k => $value) $record[$k] = '"' . $this->runner->sqlSafe($value) . '"';

            $result['values'][$row] = '(' . implode(',', $record) . ')';
        }

        foreach ($result['fields'] as $k => $field) $result['fields'][$k] = '`' . $field . '`';

        return '(' . implode(',', $result['fields']) . ') VALUES ' . implode(', ', $result['values']);
    }

    /**
     * The multi-row path stores booleans as "0"/1 and never casts numerics,
     * because every value goes through sqlSafe() and is quoted.
     */
    private function encodeValueMultiple(array $field, mixed $value): mixed
    {
        switch ($field['type'] ?? '') {
            case 'boolean':
                return ($value === 'on' || $value === '1' || $value === 1) ? 1 : "0";
            case 'table':
            case 'json':
            case 'blocks':
                return json_encode($value);
            case 'order':
                return intval($value);
            case 'elements':
            case 'checkboxes':
                return $this->encodeElementList($field, $value);
        }

        return $value;
    }

    // -------------------------------------------------------------------------
    // Prepared statements
    // -------------------------------------------------------------------------

    /**
     * Converts schema fields plus data into typed tuples for a prepared write.
     *
     * @return array{fields: array<int, string>, values: array<int, mixed>, types: array<int, string>}
     *         types use the mysqli characters s/i/d
     */
    public function buildPrepared(array $schema, array $data): array
    {
        $fields = [];
        $values = [];
        $types  = [];

        foreach ($data as $key => $value) {
            if ($key === 'id') continue;

            $field = _uho_fx::array_filter($schema['fields'], 'field', $key, ['first' => true]);
            if (!$field || in_array($field['type'] ?? '', self::SKIP_TYPES, true)) continue;
            if ($this->isReadonly($field)) continue;
            if (is_array($value) && ($value['type'] ?? null) === 'sql') continue;

            $type = 's';

            switch ($field['type'] ?? '') {
                case 'integer':
                case 'order':
                    $value = intval($value);
                    $type  = 'i';
                    break;
                case 'float':
                    $value = floatval($value);
                    $type  = 'd';
                    break;
                case 'boolean':
                    $value = ($value === true || $value === 'on' || $value === 1 || $value === '1') ? 1 : 0;
                    $type  = 'i';
                    break;
                case 'timestamp':
                    $value = (new \DateTimeImmutable((string) $value, new \DateTimeZone(date_default_timezone_get())))
                        ->setTimezone(new \DateTimeZone('UTC'))
                        ->format('Y-m-d H:i:s');
                    break;
                case 'json':
                case 'blocks':
                    if (is_array($value)) $value = json_encode($value);
                    break;
                case 'checkboxes':
                case 'elements':
                    $value = $this->encodeElementList($field, $value);
                    break;
                case 'table':
                    if (($field['settings']['format'] ?? null) === 'object') {
                        $pairs = $value;
                        $value = [];
                        foreach ($pairs as $pair) $value[$pair[0]] = $pair[1];
                    }
                    $value = json_encode($value);
                    break;
                case 'select':
                    if (is_numeric($value)) {
                        $value = intval($value);
                        $type  = 'i';
                    }
                    break;
            }

            $hash = $field['settings']['hash'] ?? null;
            if ($hash !== null)
                $value = $hash[0] === '~'
                    ? _uho_fx::encrypt($value, $this->context->getKeys(), substr($hash, 1), true)
                    : _uho_fx::encrypt($value, $this->context->getKeys(), $hash);

            $fields[] = $key;
            $values[] = $value;
            $types[]  = $type;
        }

        return compact('fields', 'values', 'types');
    }

    // -------------------------------------------------------------------------
    // Schema-driven SET clause
    // -------------------------------------------------------------------------

    /**
     * The SET part recalculating fields declared as
     * settings.auto.on_update.value_sql, run after every write.
     */
    public function buildAutoSql(array $schema): string
    {
        $set = [];

        foreach ($schema['fields'] as $field)
            if (!empty($field['settings']['auto']['on_update']['value_sql']))
                $set[] = '`' . $field['field'] . '`=' . $field['settings']['auto']['on_update']['value_sql'];

        return implode(', ', $set);
    }
}
