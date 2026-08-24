<?php

declare(strict_types=1);

namespace Huncwot\UhoFramework;

/**
 * Every fatal condition raised by the _uho_orm2 family.
 *
 * The legacy _uho_orm called exit() from halt(); here the same conditions throw,
 * so a failing query cannot take the whole request down without the caller
 * having a chance to react. _uho_orm2::setHaltMode('exit') restores the old
 * behaviour for code that relied on it.
 */
class _uho_orm2_exception extends \RuntimeException {}
