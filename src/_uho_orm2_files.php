<?php

declare(strict_types=1);

namespace Huncwot\UhoFramework;

/**
 * Turns a schema-derived media path into the path that is actually served.
 *
 * Three concerns meet here because they all rewrite the same string: the
 * cache buster (?v=mtime, or the '___' infix style), the optional folder
 * remap between the writing and the reading host, and S3 - where the file
 * time comes from the bucket listing and the host has to be prepended.
 *
 * Every method that rewrites a path takes it by reference, the way the
 * legacy ORM did, because a path that cannot be resolved becomes an empty
 * string rather than throwing.
 */
class _uho_orm2_files
{
    /** false, true, 'standard'/'medium' (normalised to true) or '___' */
    private mixed $cacheBuster = false;

    private string $cacheBusterStyle = 'standard';

    /** @var array{source: ?string, destination: ?string, s3: ?object}|null */
    private ?array $folderReplace = null;

    /** when true, image fields carry width/height next to src */
    private bool $imageSizes = false;

    public function __construct(private _uho_orm2_s3 $s3) {}

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    /**
     * @param mixed $mode false, true, 'standard', 'medium' or '___'
     */
    public function setCacheBuster(mixed $mode): void
    {
        if (is_string($mode) && in_array($mode, ['standard', 'medium'], true)) {
            $this->cacheBusterStyle = $mode;
            $mode = 1;
        }

        $this->cacheBuster = $mode;
    }

    public function setFolderReplace(?string $source, ?string $destination, ?object $s3 = null): void
    {
        $this->folderReplace = ['source' => $source, 'destination' => $destination, 's3' => $s3];
    }

    public function setImageSizes(mixed $onOff): void
    {
        $this->imageSizes = (bool) $onOff;
    }

    public function imageSizesEnabled(): bool
    {
        return $this->imageSizes;
    }

    public function getS3Manager(): _uho_orm2_s3
    {
        return $this->s3;
    }

    // -------------------------------------------------------------------------
    // Cache buster
    // -------------------------------------------------------------------------

    /**
     * Strips the '?v=...' suffix, so the result addresses the file on disk.
     */
    public function removeCacheBuster(string $filename): string
    {
        return $this->cacheBuster ? explode('?', $filename)[0] : $filename;
    }

    /**
     * Collapses a media path to a form that cannot leave the tree it names.
     *
     * Parts of these paths come from record values substituted into the
     * schema's folder and filename patterns, so a stored '../..' would
     * otherwise reach filemtime(), getimagesize() and the URLs handed to the
     * browser. Climbing segments are resolved away rather than rejected: the
     * path is still a usable one, it just cannot point above its root.
     */
    public function normalizePath(string $path): string
    {
        $path = str_replace(["\0", '\\'], ['', '/'], $path);
        $path = (string) preg_replace('#/+#', '/', $path);

        if (!str_contains($path, '.')) return $path;

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.') continue;

            if ($segment === '..') {
                // never pop the leading '' that keeps the path absolute
                if (count($segments) > 1) array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /**
     * Rewrites a path into the one that is served: folder remap, cache buster
     * and S3 host. An unresolvable file becomes an empty string.
     */
    public function addCacheBuster(string &$filename): void
    {
        $filename = $this->normalizePath($filename);

        if ($this->s3->isS3()) {
            $this->addCacheBusterS3($filename);
            return;
        }

        if ($this->cacheBuster && $this->folderReplace !== null) {
            $this->addCacheBusterRemapped($filename);
            return;
        }

        if ($this->cacheBuster) {
            $this->addCacheBusterLocal($filename);
            return;
        }

        $this->applyFolderReplace($filename);
    }

    /**
     * Replaces a bare src with ['src' =>, 'width' =>, 'height' =>] when the
     * file can be measured on disk.
     */
    public function addImageSize(string|array &$filename): void
    {
        if (!$this->cacheBuster || !is_string($filename)) return;

        $filename = $this->normalizePath($filename);

        $size = @getimagesize(rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . $filename);

        if ($size) {
            $this->addCacheBuster($filename);
            $filename = ['src' => $filename, 'width' => $size[0], 'height' => $size[1]];
        } else {
            $filename = ['src' => $filename];
        }
    }

    /**
     * Prefixes a path with an external server, trimming the duplicate slash.
     */
    public function addServer(string &$filename, string $server): void
    {
        $filename = $server . _uho_fx::trim($this->normalizePath($filename), '/');
    }

    // -------------------------------------------------------------------------
    // Cache buster strategies
    // -------------------------------------------------------------------------

    private function addCacheBusterS3(string &$filename): void
    {
        if ($this->cacheBuster) {
            $time = $this->s3->getFileTime($filename);
            $filename = $time ? $filename . '?' . $time : '';
        }

        if ($filename !== '') $filename = $this->s3->getFilenameWithHost($filename, true);
    }

    /**
     * The writing host and the reading host disagree on the folder; the file
     * time then comes either from the S3 cache or from the bucket handed to
     * setFolderReplace().
     */
    private function addCacheBusterRemapped(string &$filename): void
    {
        $this->applyFolderReplace($filename);

        if ($this->s3->getCacheData() !== null) {
            $bare = str_replace((string) $this->folderReplace['destination'], '', $filename);
            $time = $this->s3->s3get($bare);

            if ($time) $filename .= '?v=' . md5((string) $time['time']);
            elseif ($this->cacheBusterStyle === 'standard') $filename = '';

            return;
        }

        if (!empty($this->folderReplace['s3'])) {
            $time = $this->folderReplace['s3']->file_time($filename);
            $filename = $time ? $filename . '?v=' . $time : '';
        }
    }

    private function addCacheBusterLocal(string &$filename): void
    {
        $path = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . $filename;
        $time = is_dir($path) ? null : @filemtime($path);

        if (!$time) {
            $filename = '';
            return;
        }

        // '___' style keeps the query string free, for hosts that drop it
        if ($this->cacheBuster === '___') {
            $parts     = explode('.', $filename);
            $extension = array_pop($parts);
            $filename  = implode('.', $parts) . '___' . $time . '.' . $extension;
            return;
        }

        $filename .= '?v=' . $time;
    }

    private function applyFolderReplace(string &$filename): void
    {
        if ($this->folderReplace === null || empty($this->folderReplace['source'])) return;

        $filename = str_replace(
            (string) $this->folderReplace['source'],
            (string) $this->folderReplace['destination'],
            $filename
        );
    }
}
