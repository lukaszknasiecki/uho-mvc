<?php

namespace Huncwot\UhoFramework;

/**
 * UHO Upload Manager
 *
 * Handles file and image upload operations for UHO ORM
 *
 * Methods:
 * - getTempFilename(): string
 * - setTempPublicFolder($folder): void
 * - decodeBase64Image($image, $allowed_extensions): string|false
 * - uploadBase64Image($model_name, $record_id, $field_name, $image): bool
 * - uploadImage($schema, $record, $field_name, $image, $temp_filename = null): bool
 * - removeImage($model_name, $record_id, $field_name): bool
 * - copy($src, $dest, $remove_src = false): void
 */

class _uho_orm_upload
{
    private $orm;
    private $s3Manager;
    private $temp_public_folder = '/temp';

    public function __construct(_uho_orm $orm, _uho_orm_s3 $s3Manager)
    {
        $this->orm = $orm;
        $this->s3Manager = $s3Manager;
    }

    /**
     * Creates temporary filename
     */
    public function getTempFilename()
    {
        $filename = $this->temp_public_folder . '/' . uniqid();
        $filename = $_SERVER['DOCUMENT_ROOT'] . $filename;
        return $filename;
    }

    /**
     * Sets temporary publicly available temporary folder
     */
    public function setTempPublicFolder($folder)
    {
        $this->temp_public_folder = $folder;
        $this->s3Manager->setTempPublicFolder($folder);
    }

    /**
     * Decodes base64 image
     */
    private function decodeBase64Image($image, $allowed_extensions)
    {
        if ($image && is_string($image)) {
            if (preg_match('/^data:image\/(\w+);base64,/', $image, $type)) {
                $image = substr($image, strpos($image, ',') + 1);
                $type = strtolower($type[1]);
                if (in_array($type, $allowed_extensions))  return base64_decode($image);
            }
        }
        return false;
    }

    /**
     * Add base64 image to the model
     */
    public function uploadBase64Image($model_name, $record_id, $field_name, $image)
    {
        $image = $this->decodeBase64Image($image, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        $schema = $this->orm->getSchema($model_name);
        if ($image) $record = $this->orm->get($model_name, ['id' => $record_id], true);
        else $record = null;

        if ($schema && $record && isset($record[$field_name])) {
            return $this->uploadImage($schema, $record, $field_name, $image);
        }

        return false;
    }

    /**
     * Upload image to the model
     */
    public function uploadImage($schema, $record, $field_name, $image, $temp_filename = null)
    {

        $root = $_SERVER['DOCUMENT_ROOT'];
        $field = _uho_fx::array_filter($schema['fields'], 'field', $field_name, ['first' => true]);
        if (!$field) return false;

        /* retina? */
        $retina = [];
        foreach ($field['images'] as $v)
            if (!empty($v['retina'])) {
                if (isset($v['width'])) $v['width'] = $v['width'] * 2;
                if (isset($v['height'])) $v['height'] = $v['height'] * 2;
                $v['folder'] .= '_x2';
                $retina[] = $v;
            }
        if ($retina) $field['images'] = array_merge($field['images'], $retina);

        /* create original image */

        $extension = 'jpg';
        $filename = str_replace($field['settings']['filename'], '%uid%', $record['uid']) . '.' . $extension;
        $original = array_shift($field['images']);
        $original_filename = $field['settings']['folder'] . '/' . $original['folder'] . '/' . $filename;

        if ($image && !$temp_filename) {
            $temp_filename = $this->getTempFilename(true);
            if (!file_put_contents($temp_filename, $image)) {
                return false;
            }
        }

        $temp_original_filename = $temp_filename;
        $this->copy($temp_filename, $original_filename); // no-remove

        /* resize */

        $result = true;

        foreach ($field['images'] as $v) {
            if (isset($v['crop'])) $v['cut'] = $v['crop'];
            $v['enlarge'] = true;

            if ($this->s3Manager->getS3()) {
                $src = $temp_original_filename;
                $dest = $this->getTempFilename(true);
                $dest_s3 = $field['settings']['folder'] . '/' . $v['folder'] . '/' . $filename;
            } else {
                $src = $root . $original_filename;
                $dest = $root . $field['settings']['folder'] . '/' . $v['folder'] . '/' . $filename;
            }

            $r = _uho_thumb::convert(
                $filename,
                $src,
                $dest,
                $v
            );

            if (!$r['result']) $result = false;
            elseif ($this->s3Manager->getS3()) {
                $this->copy($dest, $dest_s3);
            }
        }

        return $result;
    }

    /**
     * Remove image from the model
     */
    public function removeImage($model_name, $record_id, $field_name)
    {
        $result = false;
        $record = $this->orm->get($model_name, ['id' => $record_id], true);
        $s3 = $this->s3Manager->getS3();
        if (isset($record[$field_name])) {
            $result = true;
            foreach ($record[$field_name] as $image) {
                $image = $this->orm->fileRemoveTime($image);
                if ($s3) $s3->unlink($image);
                else unlink($_SERVER['DOCUMENT_ROOT'] . $image);
            }
        }
        return $result;
    }

    /**
     * Copy file using standard or S3
     */
    public function copy($src, $dest, $remove_src = false)
    {
        if ($this->s3Manager->isS3()) $this->s3copy($src, $dest);
        else {
            $dest = $_SERVER['DOCUMENT_ROOT'] . $dest;
            copy($src, $dest);
        }
        if ($remove_src) @unlink($src);
    }

    /**
     * Copy file using S3
     * Delegates to S3Manager
     */
    private function s3copy(string $src, string $dest)
    {
        $getTempCallback = function() {
            return $this->getTempFilename(true);
        };
        $this->s3Manager->s3copy($src, $dest, $getTempCallback);
    }
}
