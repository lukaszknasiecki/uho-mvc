<?php

namespace Huncwot\UhoFramework;

/**
 * _uho_orm_s3 handles all S3-related operations for the UHO ORM
 *
 * This class manages:
 * - S3 object configuration
 * - S3 cache management
 * - S3 file operations (copy, get)
 * - S3 compression settings
 */
class _uho_orm_s3
{
    /**
     * S3 object instance
     */
    private $uhoS3 = null;

    /**
     * S3 cache array with filename and data
     */
    private $s3cache = null;

    /**
     * S3 compression method ('', 'md5')
     */
    private $s3compress = null;

    /**
     * Temporary public folder path
     */
    private $temp_public_folder = '/temp';

    /**
     * Check if S3 is enabled
     *
     * @return bool
     */
    public function isS3(): bool
    {
        return $this->uhoS3 !== null;
    }

    /**
     * Get the S3 object
     *
     * @return mixed|null
     */
    public function getS3()
    {
        return $this->uhoS3;
    }

    /**
     * Set the S3 object
     *
     * @param mixed $object S3 object instance
     * @return void
     */
    public function setS3($object): void
    {
        $this->uhoS3 = $object;
    }

    /**
     * Sets S3 cache compress method
     *
     * @param string $compress Compression method ('' or 'md5')
     * @return bool True if valid compression method, false otherwise
     */
    public function setS3Compress($compress): bool
    {
        if (in_array($compress, ['', 'md5'])) {
            $this->s3compress = $compress;
            return true;
        }
        return false;
    }

    /**
     * Get the current S3 compression method
     *
     * @return string|null
     */
    public function getS3Compress(): ?string
    {
        return $this->s3compress;
    }

    /**
     * Gets S3 cached object by filename
     *
     * @param string $filename The filename to retrieve from cache
     * @return array|null Cached data or null if not found
     */
    public function s3get(string $filename): ?array
    {
        $lookupFilename = $filename;

        switch ($this->s3compress) {
            case "md5":
                $lookupFilename = md5($filename);
                break;
        }

        $result = null;
        if (!empty($this->s3cache['data'][$lookupFilename])) {
            $result = $this->s3cache['data'][$lookupFilename];
        }

        if ($result) {
            switch ($this->s3compress) {
                case "md5":
                    $result = ['time' => $result];
                    break;
            }
        }

        return $result;
    }

    /**
     * Loads full S3 cache array (or creates it)
     *
     * @param bool $force_rebuild Force rebuild the cache from file
     * @return void
     */
    public function s3getCache(bool $force_rebuild = false): void
    {
        if ($force_rebuild || !$this->s3cache['data']) {
            $data = @file_get_contents($this->s3cache['filename']);
            if ($data) {
                $data = json_decode($data, true);
            }
            if ($data) {
                $this->s3cache['data'] = $data;
            }
        }
    }

    /**
     * Enabled S3 cache by setting cache filename and loading/creating it
     *
     * @param string $cache_filename Path to cache file
     * @return void
     */
    public function s3setCache(string $cache_filename): void
    {
        $this->s3cache = [
            'filename' => str_replace('//', '/', $_SERVER['DOCUMENT_ROOT'] . '/' . $cache_filename),
            'data' => null
        ];
        $this->s3getCache(true);
    }

    /**
     * Get the current S3 cache data
     *
     * @return array|null
     */
    public function getCacheData(): ?array
    {
        return $this->s3cache['data'] ?? null;
    }

    /**
     * Copy file to/from S3
     *
     * @param string $src Source file path
     * @param string $dest Destination file path
     * @param callable|null $getTempFilename Optional callback to get temp filename
     * @return void
     */
    public function s3copy(string $src, string $dest, ?callable $getTempFilename = null): void
    {
        $s3 = $this->getS3();

        if (substr($src, 0, 4) == 'http') {
            // S3 cannot get source stream from another S3
            if ($getTempFilename) {
                $temp_filename = $getTempFilename();
            } else {
                $temp_filename = $this->getTempFilename();
            }

            copy($src, $temp_filename);
            $s3->copy($temp_filename, $dest);
            unlink($temp_filename);
        } else {
            $s3->copy($src, $dest);
        }
    }

    /**
     * Returns current S3 cache filename
     *
     * @return string|null
     */
    public function s3getCacheFilename(): ?string
    {
        if (isset($this->s3cache) && !empty($this->s3cache['filename'])) {
            return $this->s3cache['filename'];
        }
        return null;
    }

    /**
     * Set the temporary public folder path
     *
     * @param string $folder Folder path
     * @return void
     */
    public function setTempPublicFolder(string $folder): void
    {
        $this->temp_public_folder = $folder;
    }

    /**
     * Get temporary filename (internal fallback)
     *
     * @return string
     */
    private function getTempFilename(): string
    {
        $filename = $this->temp_public_folder . '/' . uniqid();
        $filename = $_SERVER['DOCUMENT_ROOT'] . $filename;
        return $filename;
    }

    /**
     * Get file time from S3
     *
     * @param string $filename The file to get time for
     * @return int|null File modification time or null
     */
    public function getFileTime(string $filename)
    {
        if (!$this->isS3()) {
            return null;
        }

        $time = $this->uhoS3->file_time($filename);
        return $time;
    }

    /**
     * Get filename with S3 host
     *
     * @param string $filename The filename
     * @param bool $withHost Include host in URL
     * @return string
     */
    public function getFilenameWithHost(string $filename, bool $withHost = true): string
    {
        if (!$this->isS3()) {
            return $filename;
        }

        return $this->uhoS3->getFilenameWithHost($filename, $withHost);
    }
}
