<?php

namespace Huncwot\UhoFramework;

/**
 * UHO Schema Loader
 *
 * Handles schema file path management and JSON loading for UHO ORM
 *
 * Methods to handle set of paths:
 * - getRootPaths($add_root = false): array
 * - addRootPath($path): void
 * - removeRootPaths(): void
 * 
 * Main method to load Json schema
 * - loadJsonSchema($filename): array|null
 */

class _uho_orm_schema_loader
{
    /**
     * Array of root paths for schema files
     */
    private array $root_paths = [];

    /**
     * Array of errors encountered during loading
     */
    private array $errors = [];

    /**
     * Get registered root paths for schema files
     * @param bool $add_root Whether to prepend DOCUMENT_ROOT to paths
     * @return array
     */
    public function getRootPaths(bool $add_root = false): array
    {
        if ($add_root) {
            $result = [];
            foreach ($this->root_paths as $v)
                $result[] = $_SERVER['DOCUMENT_ROOT'] . $v;
            return $result;
        } else {
            return $this->root_paths;
        }
    }

    /**
     * Add a root path for schema file resolution
     * @param string $path Path to add
     * @return void
     */
    public function addRootPath(string $path): void
    {
        $this->root_paths[] = $path;
    }

    /**
     * Clear all registered root paths
     * @return void
     */
    public function removeRootPaths(): void
    {
        $this->root_paths = [];
    }

    /**
     * Loads and parses JSON file
     * Searches in registered root_paths
     *
     * @param string $filename JSON filename (with or without .json extension)
     * @return array|null Parsed JSON as array, or null if not found/corrupted
     */
    public function loadJsonSchema(string $filename): array|null
    {
        $loaded = false;
        $tried = [];

        if (!strpos($filename, '.json')) $filename .= '.json';

        foreach ($this->root_paths as $v) {
            if (empty($m)) {
                $load = $_SERVER['DOCUMENT_ROOT'] . $v . $filename;
                $tried[] = $load;
                $m = @file_get_contents($load);
                if (!$m) {
                    $load = $v . $filename;
                    $tried[] = $load;
                    $m = @file_get_contents($load);
                    if ($m) $loaded = true;
                } else $loaded = true;
            }
        }

        if (!empty($m)) $json = json_decode($m, true);

        if (empty($json) && $loaded) $this->errors[] = 'JSON corrupted: ' . $filename;
        elseif (empty($json)) $this->errors[] = 'JSON not found:loadJsonSchema: ' . implode(', ', $tried);

        if (!empty($json)) return $json;
        else return null;
    }

    /**
     * Get last error message
     * @return string|null
     */
    public function getLastError(): ?string
    {
        return empty($this->errors) ? null : end($this->errors);
    }

    /**
     * Get all error messages
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
