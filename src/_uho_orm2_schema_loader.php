<?php

declare(strict_types=1);

namespace Huncwot\UhoFramework;

/**
 * Resolves schema names to JSON files and parses them.
 *
 * A schema is looked up in every registered root path, first below
 * DOCUMENT_ROOT and then as given, so both absolute and web-relative
 * registrations work. A schema may pull in another one through 'include';
 * the included file is the base and the including file overrides it, with
 * the 'cms' section merged rather than replaced.
 */
class _uho_orm2_schema_loader
{
    /**
     * A schema name is an identifier, optionally in a subdirectory - never a
     * path. The slash is allowed because schemas are organised in folders; '..'
     * and the backslash are not, and are rejected separately below.
     */
    private const NAME_PATTERN = '#^[A-Za-z0-9_][A-Za-z0-9_./-]*$#';

    /** how many 'include' levels one schema may pull in */
    private const MAX_INCLUDE_DEPTH = 16;

    /** @var array<int, string> */
    private array $rootPaths = [];

    /** @var array<int, string> */
    private array $errors = [];

    /**
     * @return array<int, string>
     */
    public function getRootPaths(bool $addRoot = false): array
    {
        if (!$addRoot) return $this->rootPaths;

        $result = [];
        foreach ($this->rootPaths as $path) $result[] = ($_SERVER['DOCUMENT_ROOT'] ?? '') . $path;

        return $result;
    }

    public function addRootPath(string $path): void
    {
        $this->rootPaths[] = $path;
    }

    public function removeRootPaths(): void
    {
        $this->rootPaths = [];
    }

    /**
     * Loads a schema by filename, with or without the '.json' extension.
     * Returns null when the name is not a plain schema name, or when the file
     * cannot be found or does not parse.
     *
     * @param array<string, true> $seen include chain already followed, for cycle detection
     */
    public function loadJsonSchema(string $filename, array $seen = [], int $depth = 0): ?array
    {
        if (!$this->isSafeName($filename)) {
            $this->errors[] = 'JSON name rejected:loadJsonSchema: ' . $filename;
            return null;
        }

        if (!str_contains($filename, '.json')) $filename .= '.json';

        $tried    = [];
        $contents = null;

        foreach ($this->rootPaths as $path) {
            if ($contents !== null) break;

            foreach ([($_SERVER['DOCUMENT_ROOT'] ?? '') . $path . $filename, $path . $filename] as $candidate) {
                $tried[] = $candidate;
                $raw = @file_get_contents($candidate);
                if ($raw !== false && $raw !== '') {
                    $contents = $raw;
                    break;
                }
            }
        }

        if ($contents === null) {
            $this->errors[] = 'JSON not found:loadJsonSchema: ' . implode(', ', $tried);
            return null;
        }

        $json = json_decode($contents, true);

        if (empty($json) || !is_array($json)) {
            $this->errors[] = 'JSON corrupted: ' . $filename;
            return null;
        }

        if (!empty($json['include'])) $json = $this->applyInclude($json, $seen, $depth);

        return $json;
    }

    /**
     * A schema name must not be able to leave the registered folders. '..' is
     * rejected outright rather than normalised, because a name that tries to
     * climb is a mistake worth reporting, not something to quietly repair.
     */
    private function isSafeName(string $filename): bool
    {
        if ($filename === '' || strlen($filename) > 255) return false;
        if (str_contains($filename, "\0") || str_contains($filename, '\\')) return false;
        if (str_contains($filename, '..')) return false;

        return (bool) preg_match(self::NAME_PATTERN, $filename);
    }

    public function getLastError(): ?string
    {
        return $this->errors ? $this->errors[array_key_last($this->errors)] : null;
    }

    /**
     * @return array<int, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Merges the included schema underneath the including one, keeping both
     * 'cms' sections instead of letting the outer one replace the inner.
     *
     * Two schemas that include each other would otherwise recurse until the
     * process runs out of memory, so the chain followed so far is carried down
     * and a repeat ends the descent.
     *
     * @param array<string, true> $seen
     */
    private function applyInclude(array $json, array $seen = [], int $depth = 0): array
    {
        $include = (string) $json['include'];

        if (isset($seen[$include]) || $depth >= self::MAX_INCLUDE_DEPTH) {
            $this->errors[] = isset($seen[$include])
                ? 'JSON include cycle: ' . $include . ' (chain: ' . implode(' -> ', array_keys($seen)) . ')'
                : 'JSON include too deep (' . self::MAX_INCLUDE_DEPTH . '): ' . $include;

            unset($json['include']);
            return $json;
        }

        $seen[$include] = true;

        $base = $this->loadJsonSchema($include, $seen, $depth + 1);
        if (!$base) return $json;

        if (!empty($base['cms'])) {
            $json['cms'] = array_merge($base['cms'], $json['cms'] ?? []);
            unset($base['cms']);
        }

        return array_merge($base, $json);
    }
}
