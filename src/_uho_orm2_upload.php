<?php

declare(strict_types=1);

namespace Huncwot\UhoFramework;

/**
 * Writes the image variants a schema declares.
 *
 * The original is stored first - locally, or through a temporary file when the
 * target is S3 - and every other variant is produced from it by _uho_thumb.
 * Nothing here decides where a file lives: the paths come from the schema, the
 * same way the reader derives them.
 *
 * Failures are collected rather than thrown, because a half-uploaded set of
 * variants is still worth reporting on; getLogs() returns them.
 */
class _uho_orm2_upload
{
    private const BASE64_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** image types accepted after inspecting the bytes, not the label */
    private const ALLOWED_IMAGE_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

    /** a written filename is one name, not a path */
    private const FILENAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /** @var array<int, string> */
    private array $logs = [];

    private string $tempPublicFolder = '/temp';

    public function __construct(
        private _uho_orm2 $orm,
        private _uho_orm2_s3 $s3
    ) {}

    // -------------------------------------------------------------------------
    // Temporary files
    // -------------------------------------------------------------------------

    /**
     * A name nobody can guess: this file holds the upload in its original form
     * for as long as the conversion takes, and the folder is public.
     */
    public function getTempFilename(): string
    {
        return ($_SERVER['DOCUMENT_ROOT'] ?? '') . $this->tempPublicFolder . '/' . bin2hex(random_bytes(16));
    }

    public function setTempPublicFolder(string $folder): void
    {
        $this->tempPublicFolder = $folder;
        $this->s3->setTempPublicFolder($folder);
    }

    /**
     * @return array<int, string>
     */
    public function getLogs(): array
    {
        return $this->logs;
    }

    private function addLog(string $message): void
    {
        $this->logs[] = $message;
    }

    // -------------------------------------------------------------------------
    // Entry points
    // -------------------------------------------------------------------------

    /**
     * Stores a 'data:image/...;base64,...' payload into a record's image field.
     */
    public function uploadBase64Image(string $modelName, mixed $recordId, string $fieldName, mixed $image): bool
    {
        $decoded = $this->decodeBase64Image($image);
        if ($decoded === null) return false;

        $schema = $this->orm->getSchema($modelName);
        $record = $this->orm->get($modelName, ['id' => $recordId], true);

        if (!$schema || !$record || !isset($record[$fieldName])) return false;

        return (bool) $this->uploadImage($schema, $record, $fieldName, $decoded);
    }

    /**
     * Stores an image already present on disk into a record's image field.
     */
    public function uploadSrcImage(string $modelName, mixed $recordId, string $fieldName, ?string $imageSrc): bool
    {
        if (!$imageSrc) return false;

        $schema = $this->orm->getSchema($modelName);
        $record = $this->orm->get($modelName, ['id' => $recordId], true);

        if (!$schema || !$record || !isset($record[$fieldName])) return false;

        return (bool) $this->uploadImage($schema, $record, $fieldName, null, $imageSrc);
    }

    /**
     * Writes the original and every declared variant.
     *
     * @param mixed $image raw image data, when there is no file yet
     * @param string|null $tempFilename an existing file to use as the source
     * @return bool true when every variant was produced
     */
    public function uploadImage(
        array $schema,
        array $record,
        string $fieldName,
        mixed $image,
        ?string $tempFilename = null,
        ?string $tempFolder = null
    ): bool {
        $field = _uho_fx::array_filter($schema['fields'], 'field', $fieldName, ['first' => true]);

        if (!$field) {
            $this->addLog('Field not found in schema: ' . $fieldName);
            return false;
        }

        $variants = $this->expandRetina($field['images'] ?? []);
        if (!$variants) {
            $this->addLog('Field has no image variants: ' . $fieldName);
            return false;
        }

        $extension = 'jpg';
        $filename  = str_replace('%uid%', (string) ($record['uid'] ?? ''), $field['settings']['filename'] ?? '%uid%')
            . '.' . $extension;

        /**
         * The name is built from a record value, and it is about to become a
         * path handed to copy() and unlink(). basename() removes any climbing
         * segment; the pattern then rejects what is left if it is still not a
         * plain filename, because a caller supplying '../..' here is not making
         * a request worth guessing at.
         */
        $filename = $this->safeFilename($filename);

        if ($filename === null) {
            $this->addLog('Refusing unsafe image filename for field: ' . $fieldName);
            return false;
        }

        $original         = array_shift($variants);
        $originalFilename = $field['settings']['folder'] . '/' . $original['folder'] . '/' . $filename;

        if ($image && !$tempFilename) {
            if (is_string($image) && !$this->isImage($image)) {
                $this->addLog('Refusing upload: content is not a supported image');
                return false;
            }

            $tempFilename = $this->writeTempFile($image, $tempFolder);
            if ($tempFilename === null) return false;
        }

        $this->copy((string) $tempFilename, $originalFilename);

        $root   = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $result = true;

        foreach ($variants as $variant) {
            if (isset($variant['crop'])) $variant['cut'] = $variant['crop'];
            $variant['enlarge'] = true;

            if ($this->s3->getS3()) {
                $source      = (string) $tempFilename;
                $destination = $this->getTempFilename();
                $destinationS3 = $field['settings']['folder'] . '/' . $variant['folder'] . '/' . $filename;
            } else {
                $source      = $root . $originalFilename;
                $destination = $root . $field['settings']['folder'] . '/' . $variant['folder'] . '/' . $filename;
                $destinationS3 = null;
            }

            $converted = _uho_thumb::convert($filename, $source, $destination, $variant);

            if (empty($converted['result'])) {
                $this->addLog('Failed to resize image: ' . implode(', ', $converted['errors'] ?? []));
                $result = false;
            } elseif ($destinationS3 !== null) {
                $this->copy($destination, $destinationS3);
            }
        }

        return $result;
    }

    /**
     * Removes every variant of a record's image field.
     */
    public function removeImage(string $modelName, mixed $recordId, string $fieldName): bool
    {
        $record = $this->orm->get($modelName, ['id' => $recordId], true);
        if (!isset($record[$fieldName])) return false;

        $s3 = $this->s3->getS3();

        $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';

        foreach ($record[$fieldName] as $image) {
            $image = $this->orm->fileCacheBuster(is_array($image) ? ($image['src'] ?? '') : $image);
            if (!is_string($image) || $image === '') continue;

            if ($s3) {
                $s3->unlink($image);
                continue;
            }

            // these paths are built from record values, so the target is
            // confirmed to sit under the document root before it is removed
            $target = realpath($root . $image);

            if ($target === false || $root === '' || !str_starts_with($target, $root . DIRECTORY_SEPARATOR)) {
                $this->addLog('Refusing to remove a file outside the document root: ' . $image);
                continue;
            }

            @unlink($target);
        }

        return true;
    }

    /**
     * Copies a local file to its place in the public tree, or into the bucket.
     */
    public function copy(string $src, string $dest, bool $removeSrc = false): void
    {
        if ($this->s3->isS3()) {
            $this->s3->s3copy($src, $dest, fn() => $this->getTempFilename());
        } else {
            $target = ($_SERVER['DOCUMENT_ROOT'] ?? '') . $dest;
            @copy($src, $target);

            if (!file_exists($target)) $this->addLog('Failed to copy file: ' . $src . ' to ' . $target);
        }

        if ($removeSrc) @unlink($src);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Decodes a data: URI, accepting only the image types we can process.
     */
    private function decodeBase64Image(mixed $image): ?string
    {
        if (!is_string($image) || !preg_match('/^data:image\/(\w+);base64,/', $image, $matches)) return null;

        if (!in_array(strtolower($matches[1]), self::BASE64_EXTENSIONS, true)) return null;

        $decoded = base64_decode(substr($image, strpos($image, ',') + 1), true);
        if ($decoded === false || $decoded === '') return null;

        // the label above is written by whoever sent the payload; only the
        // bytes say what this actually is
        return $this->isImage($decoded) ? $decoded : null;
    }

    /**
     * True when the bytes parse as one of the image types we can convert.
     */
    private function isImage(string $bytes): bool
    {
        $info = @getimagesizefromstring($bytes);

        return $info !== false && in_array($info[2] ?? null, self::ALLOWED_IMAGE_TYPES, true);
    }

    /**
     * Reduces a derived name to a single filename, or null when what is left
     * is not one.
     */
    private function safeFilename(string $filename): ?string
    {
        $filename = basename(str_replace(["\0", '\\'], ['', '/'], $filename));

        return preg_match(self::FILENAME_PATTERN, $filename) ? $filename : null;
    }

    private function writeTempFile(mixed $image, ?string $tempFolder): ?string
    {
        $filename = $this->getTempFilename();

        if ($tempFolder) {
            if (!is_dir($tempFolder)) mkdir($tempFolder, 0755, true);
            $filename = rtrim($tempFolder, '/') . '/' . basename($filename);
        }

        if (!@file_put_contents($filename, $image)) {
            $this->addLog('Failed to write image to temporary file: ' . $filename);
            return null;
        }

        return $filename;
    }

    /**
     * A variant marked retina is produced a second time at twice the size,
     * into a '_x2' folder.
     */
    private function expandRetina(array $variants): array
    {
        $retina = [];

        foreach ($variants as $variant)
            if (!empty($variant['retina'])) {
                if (isset($variant['width']))  $variant['width']  *= 2;
                if (isset($variant['height'])) $variant['height'] *= 2;
                $variant['folder'] .= '_x2';

                $retina[] = $variant;
            }

        return $retina ? array_merge($variants, $retina) : $variants;
    }
}
