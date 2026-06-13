<?php

namespace Huncwot\UhoFramework;

/**
 * MySQL ORM subclass using standard string-interpolated queries.
 * Behavior is identical to _uho_orm; this class exists to provide
 * an explicit _uho_mysqli type hint and act as the base for the
 * prepared-statement variant.
 */
class _uho_orm_mysql extends _uho_orm
{
    function __construct(_uho_mysqli|null $sql, string|null $lang, array $keys, bool $test = false)
    {
        parent::__construct($sql, $lang, $keys, $test);
    }
}
