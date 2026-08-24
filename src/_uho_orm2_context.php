<?php

declare(strict_types=1);

namespace Huncwot\UhoFramework;

/**
 * Runtime state shared by the whole _uho_orm2 family.
 *
 * The legacy class kept the SQL handle, the encryption keys, the language set,
 * the debug flags and the error log as private properties and passed $this
 * around; every collaborator therefore had access to everything. Here that
 * state lives in one small object with an explicit surface, and each
 * collaborator receives only this - not the ORM facade.
 */
class _uho_orm2_context
{
    public const HALT_EXCEPTION = 'exception';
    public const HALT_EXIT      = 'exit';

    /** current language code, i.e. 'en' */
    private ?string $lang = null;

    /** current language column suffix, i.e. '_EN' */
    private ?string $langAdd = null;

    /** @var array<int, array{lang: string, lang_add: string}> */
    private array $langs = [];

    /** @var array<int, string> query errors, newest last */
    private array $errors = [];

    private bool $debug = false;

    private string $haltMode = self::HALT_EXCEPTION;

    /**
     * @param object|null $sql  _uho_mysqli/_uho_pgsql instance, null in test mode
     * @param array $keys       encryption keys used by _uho_fx::encrypt()/decrypt()
     * @param bool $test        skips sanitization when no SQL handle is present
     */
    public function __construct(
        private ?object $sql,
        private array $keys,
        private bool $test = false
    ) {}

    // -------------------------------------------------------------------------
    // SQL handle
    // -------------------------------------------------------------------------

    public function getSql(): ?object
    {
        return $this->sql;
    }

    public function hasSql(): bool
    {
        return $this->sql !== null;
    }

    public function isTest(): bool
    {
        return $this->test;
    }

    // -------------------------------------------------------------------------
    // Encryption keys
    // -------------------------------------------------------------------------

    public function getKeys(): array
    {
        return $this->keys;
    }

    public function setKeys(array $keys): void
    {
        $this->keys = $keys;
    }

    // -------------------------------------------------------------------------
    // Languages
    // -------------------------------------------------------------------------

    public function getLang(): ?string
    {
        return $this->lang;
    }

    public function getLangAdd(): ?string
    {
        return $this->langAdd;
    }

    public function setLanguage(?string $lang): void
    {
        $this->lang = $lang;
        if ($lang !== null && $lang !== '') $this->langAdd = '_' . strtoupper($lang);
    }

    /**
     * @return array<int, array{lang: string, lang_add: string}>
     */
    public function getLanguages(): array
    {
        return $this->langs;
    }

    /**
     * @param iterable<string> $languages
     */
    public function setLanguages(iterable $languages): void
    {
        $this->langs = [];
        foreach ($languages as $lang)
            $this->langs[] = ['lang' => (string) $lang, 'lang_add' => '_' . strtoupper((string) $lang)];
    }

    // -------------------------------------------------------------------------
    // Debug
    // -------------------------------------------------------------------------

    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    // -------------------------------------------------------------------------
    // Errors
    // -------------------------------------------------------------------------

    public function addError(string $error): void
    {
        $this->errors[] = $error;
    }

    /**
     * @return array<int, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getLastErrorMessage(): ?string
    {
        return $this->errors ? $this->errors[array_key_last($this->errors)] : null;
    }

    // -------------------------------------------------------------------------
    // Fatal conditions
    // -------------------------------------------------------------------------

    public function setHaltMode(string $mode): void
    {
        if (!in_array($mode, [self::HALT_EXCEPTION, self::HALT_EXIT], true))
            throw new _uho_orm2_exception('_uho_orm2::unknown halt mode: ' . $mode);

        $this->haltMode = $mode;
    }

    public function getHaltMode(): string
    {
        return $this->haltMode;
    }

    /**
     * Aborts the current operation. Throws by default; exits when the legacy
     * behaviour has been requested explicitly.
     */
    public function halt(string $message): never
    {
        // the message carries caller input (a schema name, a query), so it is
        // escaped before it can reach a browser
        if ($this->haltMode === self::HALT_EXIT)
            exit('<pre>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</pre>');

        throw new _uho_orm2_exception($message);
    }
}
