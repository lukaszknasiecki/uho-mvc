<?php

namespace Huncwot\UhoFramework;

/**
 * This class extends phpThumb class and supports advanced
 * resize/cropping functions for images
 */

class _uho_thumb
{
    /**
     * indicates if class is initialized
     */
    private static $initialized = false;
    /**
     * indicates if we should use imagemagick, not GD
     */
    private static $config_prefer_imagemagick = false;
    /**
     * path to imagemagic exe file
     */
    private static $config_imagemagick_path = 'convert';

    /**
     * Static class init
     *
     * @return void
     */
    private static function initialize()
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
    }

    /**
     * Adds error to the log
     *
     * @param array $errors
     * @param int $nr
     * @param $additional
     */
    private static function addError(&$errors, $nr, $additional = null): void
    {

        $err =
            [
                1 => [
                    'Obraz nie został zapisany na serwer, prawdopodobnie brak dostępu do folderu temporary.',
                    'Temporary server access error'
                ],
                2 => [
                    'Obraz nie został zapisany na serwer - BRAK BIBLIOTEKI GD',
                    'Image errors. GD library is missing'
                ]

            ];

        $errors[] = $err[$nr][0];
        if (is_array($additional) && $additional) $errors = array_merge($errors, $additional);
        elseif (is_string($additional)) $errors[] = $additional;
    }


    /**
     * Checks if magic bytes are fine for JPG/PNG/GIF
     *
     * @param string $filename
     * @param string $file
     *
     * @return (bool|string)[]|false
     *
     * @psalm-return array{result: bool, errors: string}|false
     */
    public static function fileMagicBytesCheck($filename, $file): array|false
    {
        if ($filename) {
            $ext = explode('.', strtolower($filename));
            array_pop($ext);
        } else
            $result = false;
        $errors = '';

        $bytes = array('jpeg' => 'FF D8', 'jpg' => 'FF D8', 'gif' => '47 49 46 38', 'png' => '89 50 4E 47 0D 0A 1A 0A', 'webp' => '52 49 46 46');

        $handle = @fopen($file, "r");
        if (!$handle) return false;

        // read file beginning
        if (!$handle) {
            $errors = 'Cannot read source file (' . $file . ')';
        } else {
            $contents = fread($handle, 10);

            foreach ($bytes as $v) {
                if (empty($result)) {
                    $b = explode(' ', $v);
                    $bb = '';
                    for ($i = 0; $i < count($b); $i++) {
                        $bb .= chr(hexdec($b[$i]));
                    }
                    if ($bb == substr($contents, 0, strlen($bb))) {
                        $result = true;
                    }
                }
            }
            if (!$result) {
                $errors = 'Wrong file structure for ' . $filename;
            }
        }

        return array('result' => $result, 'errors' => $errors);
    }

    /**
     * Sets/Unsets imagic as prefered library
     *
     * @param boolean $q
     * @param string $path
     */
    public static function set_config_prefer_imagemagick($q, $path = null): void
    {
        self::$config_prefer_imagemagick = $q;
        if ($path) self::$config_imagemagick_path = $path;
    }

    /**
     * Main image resize function
     * @param string $source_filename
     * @param string $file1
     * @param string $file2
     * @param array $v
     * @param boolean $copyOnly
     * @param int $nr
     * @param array $predefined_crop
     * @param boolean $useNative
     * @param boolean $useBytesCheck
     * @return array
     */

    public static function convert($source_filename, $file1, $file2, $v, $copyOnly = false, $nr = 1, $predefined_crop = null, $useNative = false, $magicBytesCheck = true)
    {

        if (!$file1) return ['result' => false, 'errors' => ['file1 missing']];
        $webp = @$v['webp'];
        if (isset($v['use_native'])) $useNative = $v['use_native'];

        if (isset($v['mask'])) {
            if (is_array($v['mask']['image'])) {
                $v['mask']['image'] = $v['mask']['image']['image'];
            }
            if (!is_array($v['mask'])) {
                $mask = array('type' => 'intersection', 'image' => $v['mask']);
            } else {
                $mask = $v['mask'];
            }
        }

        $errors = array();
        $comments = array();

        ini_set('memory_limit', '1024M');

        $dir2 = dirname($file2);
        if (!file_exists($dir2)) {
            array_push($comments, 'Utworzono folder:' . $dir2);
            mkdir($dir2, 0755, true);
        }

        $source_external = (substr($file1, 0, 4) == 'http');

        if (!$source_external && !file_exists($file1)) {
            array_push($errors, '[error][phpThumb] Source file not found: ' . $file1);
            return (array('result' => false, 'comments' => $comments, 'errors' => $errors));
        }



        if (!$source_external && $magicBytesCheck) {
            $magic_bytes = _uho_thumb::fileMagicBytesCheck($source_filename, $file1);
            if (!$magic_bytes['result']) {
                array_push($errors, '[error] Magic bytes incorrect for (' . $file1 . ') - ' . $magic_bytes['errors']);
                return (array('result' => false, 'comments' => $comments, 'errors' => $errors));
            }
        }

        if (!$source_filename) $source_filename = '';
        if (strpos($source_filename, '.png')) {
            $pic = @imagecreatefrompng($file1);
        } elseif (strpos($source_filename, '.gif')) {
            $pic = @imagecreatefromgif($file1);
        } elseif (strpos($source_filename, '.webp')) {
            $pic = @imagecreatefromwebp($file1);
        } else {
            $type = exif_imagetype($file1);
            $filepath = $file1;
            switch ($type) {
                case 1:
                    $pic = imageCreateFromGif($filepath);
                    break;
                case 2:
                    $pic = imageCreateFromJpeg($filepath);
                    break;
                case 3:
                    $pic = imageCreateFromPng($filepath);
                    break;
                case 6:
                    $pic = imageCreateFromBmp($filepath);
                    break;
                case 18:
                    $pic = imageCreateFromWebp($filepath);
                    break;
            }
        }

        if (!$pic) {
            if (file_exists($file1)) {
                array_push($errors, '[error] [image-bad-format] (' . $file1 . ')');
            } else {
                array_push($errors, '[error] [image-not-found] (' . $file1 . ')');
            }
            return (array('result' => false, 'comments' => $comments, 'errors' => $errors));
        }

        $width = imagesx($pic);
        $height = imagesy($pic);
        $ratio = $width / $height;


        // --------------------------------------------------------------------
        // ratio -> ratio Array,ie. [1,1.25,1.5] -> need to find nearest ratio
        // --------------------------------------------------------------------
        if (isset($v['ratio'])) {
            $min = 1;
            $bestratio = 0;
            if (!is_array($v['ratio'])) {
                $v['ratio'] = array('0' => $v['ratio']);
            }
            // looking for the closest ratio
            foreach ($v['ratio'] as $kk => $vv) {
                if (abs($vv - $ratio) < $min) {
                    $min = abs($vv - $ratio);
                    $bestratio = $kk;
                }
            }
            $bestratio = $v['ratio'][$bestratio];
            if ($v['width']) {
                $v['height'] = $v['width'] / $bestratio;
            } else {
                $v['width'] = $v['height'] * $bestratio;
            }
            $v['cut'] = 1;
            unset($v['ratio']);
        } elseif // cut -> ratio
        (isset($v['cut']) && (strpos($v['cut'], '.') || strpos($v['cut'], '-'))) {
            $v['ratio'] = explode('-', $v['cut']);
            $v['cut'] = null;
        }

        $goodratio = true;


        if (isset($v['ratio']) && $v['ratio'] && $v['ratio'][1] && ($ratio < $v['ratio'][0] || $ratio > $v['ratio'][1])) {
            $goodratio = false;
        }
        if (isset($v['ratio']) && $v['ratio'] && !$v['ratio'][1] && $ratio != $v['ratio'][0]) {
            $goodratio = false;
        }
        if ((!isset($v['cut']) || !$v['cut']) && (!isset($v['enlarge']) || !$v['enlarge']) && (!isset($v['width']) || $width <= $v['width']) && (!isset($v['height']) || $height <= $v['height'])) {
            $forceCopy = true;
        }

        // webp
        $file2webp = explode('.', $file2);
        if (count($file2webp) == 1) {
            $file2webp = $file2 . '_webp';
        } else {
            array_pop($file2webp);
            array_push($file2webp, 'webp');
            $file2webp = implode('.', $file2webp);
        }
        //----------------------------------------------------------------------------------------------
        // 1. copy

        if (($goodratio || $forceCopy) && !$predefined_crop &&
            (
                ((!isset($v['width']) || $width <= $v['width']) && (!isset($v['height']) || $height <= $v['height']) && (!isset($v['cut']) || $v['cut'] == 0))
                || (empty($v['width']) && empty($v['height']))
                || (isset($v['width']) && isset($v['height']) && $v['width'] == $width && $height == $v['height'])
                || (isset($v['width']) && $v['width'] == $width && empty($v['height']))
            )
        ) {

            if ($copyOnly) {
                $r = @copy($file1, $file2);
            } else {
                $r = @move_uploaded_file($file1, $file2);
            }

            if ($webp && $pic) {
                @unlink($file2webp);
                if (function_exists('imagewebp')) {
                    imagewebp($pic, $file2webp, 85);
                }
            }

            if ($r) {
                _uho_thumb::applyPostFilters($file2, @$v['mask']);
                if (defined("developer") && developer == 1) {
                    array_push($comments, '[OK] Obrazek (' . $source_filename . ') jest w docelowym rozmiarze - został przekopiowany (' . $nr . ',' . $file2 . ')');
                }
            } else {
                array_push($errors, '[ERROR 34] PROBABLY NO FOLDER ACCESS (' . $file1 . ' [' . $width . 'x' . $height . '] -> ' . $file2 . ').');
            }
        }
        //----------------------------------------------------------------------------------------------------
        // 2. crop

        elseif (!isset($v['cut']) || $v['cut'] == 0 || $v['cut'] == 1 || !$goodratio) {
            if ($predefined_crop) {
            } elseif // ------------------------------
            // by ratio
            (!$goodratio) {
                $x1 = 0;
                $y1 = 0;
                $xx1 = $v['width'];
                $yy1 = $v['height'];
                if (!$xx1 && $yy1) {
                    if ($ratio > $v['ratio'][1]) {
                        $newratio = $v['ratio'][1];
                    } else {
                        $newratio = $v['ratio'][0];
                    }
                    $v['width'] = $newratio * $v['height'];
                    if ($newratio < $ratio) {
                        $xx1 = ($width * $newratio / $ratio);
                        $yy1 = $height;
                        $y1 = 0;
                        $x1 = (($width - $height * $newratio) / 2);
                    } else {
                        $xx1 = $width;
                        $yy1 = $height * $ratio / $newratio;
                        $y1 = ($height - $width * $newratio) / 2;
                        $x1 = 0;
                    }
                }
            }
            // ------------------------------
            // no cropping
            elseif (!isset($v['cut']) || $v['cut'] == 0) {
                $x1 = 0;
                $y1 = 0;
                $xx1 = $width;
                $yy1 = $height;

                if (!isset($v['height']) || !$v['height']) {
                    $v['height'] = round($v['width'] * $yy1 / $xx1);
                } elseif (empty($v['width'])) {
                    $v['width'] = round($v['height'] * $xx1 / $yy1);
                }

                if ($v['width'] / $v['height'] > $xx1 / $yy1) {
                    $v['width'] = round($v['height'] * $xx1 / $yy1);
                } else {
                    $v['height'] = round($v['width'] * $yy1 / $xx1);
                }
            }
            // ------------------------------
            // cropping with enlarging blank area
            /*
            elseif ($v['enlarge']) {
                
                $r0=$width/$height;
                $r1=$v['width']/$v['height'];

                // need to add vertical space
                if ($r0>$r1) {
                    $x1=0;
                    $y1=0;
                    $xx1=$width;
                    $yy1=$height;
                    $destX=0;
                    $destY=20;
                }
            }*/


            // cropping classic
            else {
                // -------------------------------------------------
                // vertical area cut
                if (($v['width'] / $width) > ($v['height'] / $height)) {
                    $x1 = 0;
                    if ($width < $v['width']) { // enlarging
                        $newratio = $v['width'] / $v['height'];
                        $xx1 = $v['width'];
                        $yy1 = $v['width'] / $newratio;
                        $y1 = ($height - $width / $newratio) / 2;

                        if ($y1 < 0) {
                            $x1 = 0;
                            $xx1 = $width;
                            $yy1 = $width / $newratio;
                            $y1 = $height / 2 - $yy1 / 2;
                        }
                    } else { // reducing

                        $newratio = $v['width'] / $v['height'];
                        $xx1 = $width;
                        $yy1 = ($width / $newratio);
                        $x1 = 0;
                        $cutHeight = $width / $newratio;
                        $y1 = (($height - $cutHeight) / 2);
                    }
                }
                // -------------------------------------------------
                // obcinamy w poziomie
                else {
                    $y1 = 0;
                    if (isset($v['enlarge']) && $v['enlarge'] && $height < $v['height']) { // enlarging

                        $newratio = $v['width'] / $v['height'];
                        $x1 = (($width - $height * $newratio) / 2);
                        $y1 = 0;
                        $xx1 = ($width - $x1 * 2);
                        $yy1 = ($xx1 / $newratio);
                    } else { // reducing
                        $yy1 = $height;
                        if ($v['width']) $xx1 = ($height / ($v['height'] / $v['width']));
                        $x1 = ($width / 2 - $xx1 / 2);
                    }
                }
            }

            //================================================================
            if (!$x1) $x1 = 0;
            if (!$y1) $y1 = 0;
            if (!$xx1) $xx1 = 0;
            if (!$yy1) $yy1 = 0;

            $x1 = round($x1);
            $y1 = round($y1);
            $xx1 = round($xx1);
            $yy1 = round($yy1);


            if ($predefined_crop) {

                $x1 = $predefined_crop['x1'];
                $y1 = $predefined_crop['y1'];
                $xx1 = $predefined_crop['width'];
                $yy1 = $predefined_crop['height'];

                if (!$v['width'] || !$v['height']);
                elseif ($v['width'] / $v['height'] > $xx1 / $yy1) {
                    $v['width'] = round($v['height'] * $xx1 / $yy1);
                } else {
                    $v['height'] = $v['width'] * $yy1 / $xx1;
                }
            }

            $y1 = round($y1);     // all because phpthumb exceed bug
            if ($x1 == 1) {
                $x1 = 0;
            }
            if ($y1 == 1) {
                $y1 = 0;
            }


            if (isset($v['after'])) {
                if ($v['after']['height'] == 'max') {
                    $old_yy1 = $yy1;
                    $yy1 = $height - $y1;
                    $v['height'] = ($v['height'] * $yy1 / $old_yy1);
                }
            }

            if ($useNative) {

                if (!$v['height']) {
                    $v['height'] = intval($v['width'] * $yy1 / $xx1);
                }
                $comment = 'Native Source file [' . $width . 'x' . $height . ']<br>Crop array=[' . $x1 . ',' . $y1 . ',' . $xx1 . ',' . $yy1 . ']->[' . $v['width'] . ',' . $v['height'] . ']';
                $comments[] = $comment;
                $v['width'] = round($v['width']);
                $v['height'] = round($v['width']);
                $image_p = imagecreatetruecolor(($v['width']), $v['height']);
                imagecopyresampled($image_p, $pic, 0, 0, $x1, $y1, $v['width'], $v['height'], $xx1, $yy1);
                imageJPEG($image_p, $file2, 90);

                $result = true;

                return (array('result' => $result, 'comments' => $comments, 'errors' => $errors));
            }

            $phpThumb1 = new \phpThumb();
            $phpThumb1->setParameter('config_allow_src_above_docroot', true);

            if (self::$config_prefer_imagemagick == true) {
                $phpThumb1->config_prefer_imagemagick = true;
                $phpThumb1->config_imagemagick_path = (self::$config_imagemagick_path);
            } else {
                $phpThumb1->config_prefer_imagemagick = false;
            }

            $phpThumb1->setSourceFilename($file1);

            if (!$v['height']) {
                $v['height'] = intval($v['width'] * $yy1 / $xx1);
            }
            if (isset($v['cut']) && $v['cut'] && isset($v['enlarge']) && $v['enlarge']) {
                $phpThumb1->far = 'C';
            }

            $v['width'] = intval($v['width']);
            $v['height'] = intval($v['height']);

            $phpThumb1->sx = $x1;
            $phpThumb1->sy = $y1;
            $phpThumb1->sw = intval($xx1);
            $phpThumb1->sh = intval($yy1);

            $phpThumb1->w = $v['width'];
            $phpThumb1->h = $v['height'];

            $comment = '[COMMENT] Source file: ' . $file1 . ' [' . $width . 'x' . $height . ']<br>Crop array=[' . $x1 . ',' . $y1 . ',' . $xx1 . ',' . $yy1 . ']->[' . $v['width'] . ',' . $v['height'] . ']';

            array_push($comments, $comment);

            if (isset($v['output'])) {
                $phpThumb1->config_output_format = $v['output'];
            } else {
                $phpThumb1->config_output_format = 'jpeg';
            }

            $phpThumb1->aoe = @$v['enlarge'] ? $v['enlarge'] : false;


            if (isset($v['filters'])) {
                $filters = $v['filters'];
                if (!is_array($filters)) {
                    $filters = [$filters];
                } {
                    foreach ($filters as $vv) {
                        $phpThumb1->fltr[] = $vv;
                    }
                }
            }

            if (isset($mask)) {

                switch ($mask['type']) {
                    case "overlay":
                        $phpThumb1->fltr[] = 'over|' . $_SERVER['DOCUMENT_ROOT'] . $mask['image'] . '|0|0|50';
                        break;

                    case "intersection":
                        $phpThumb1->fltr[] = 'mask|' . $_SERVER['DOCUMENT_ROOT'] . $mask['image'];
                        break;
                }
                if ($v['background']) {
                    $phpThumb1->bg = $v['background'];
                }
            }

            if (isset($v['q'])) {
                $phpThumb1->q = $v['q'];
            } else {
                $phpThumb1->q = 90;
            }

            /*
                PhpThumb shows some warings
                That's to remove them
            */
            $ini_errors = ini_get('display_errors');
            ini_set('display_errors', 0);

            if ($phpThumb1->GenerateThumbnail()) {

                $resizedxx = $phpThumb1->thumbnail_image_width;
                $resizedyy = $phpThumb1->thumbnail_image_height;

                @unlink($file2);

                if (!$phpThumb1->RenderToFile($file2)) {
                    array_push($errors, implode("<br>", $phpThumb1->debugmessages) . '<br><b>Error resizing</b> ' . $file1 . '-->' . $file2 . ' (' . $resizedxx . 'x' . $resizedyy . ')');
                } else
                // success
                {

                    _uho_thumb::applyPostFilters($file2, @$v['mask']);

                    if ($webp) {
                        @unlink($file2webp);
                        $phpThumb1->config_output_format = 'webp';
                        $phpThumb1->thumbnailFormat = 'webp';

                        $phpThumb1->GenerateThumbnail(); {
                            $phpThumb1->RenderToFile($file2webp);
                        }
                    }

                    array_push($comments, '[OK] Obrazek (' . $source_filename . ') został poprawnie zeskalowany i zapisany (' . $resizedxx . 'x' . $resizedyy . ') jako ' . $file2);
                }
            } else {
                if (!function_exists('imageantialias')) _uho_thumb::addError($errors, 2);
                elseif (!is_uploaded_file($file1)) {
                    //print_r($phpThumb1->debugmessages);
                    _uho_thumb::addError($errors, 1); //,$phpThumb1->debugmessages);



                }
            }

            if ($ini_errors !== false) ini_set('display_errors', $ini_errors);
        }



        //if ($test) {
        //    exit('x!'.implode('<br>', $comments).implode('<br>', $errors));
        //}


        if ($errors) {
            $result = false;
        } else {
            $result = true;
        }
        return (array('result' => $result, 'webp' => @$file2webp, 'comments' => $comments, 'errors' => $errors));
    }

    /**
     * Applies post-filters to resized image
     *
     * @param string $file2
     * @param object $mask
     */
    private static function applyPostFilters($file2, $mask): void
    {

        $size = @getimagesize($file2);
        if ($size && $mask)
            switch ($mask['type']) {

                case "blend":

                    $resizedxx = intval($size[0]);
                    $resizedyy = intval($size[1]);

                    $command = '/usr/local/bin/convert ' . $file2 . ' ' . $_SERVER['DOCUMENT_ROOT'] . $mask['image'] . ' -resize ' . $resizedxx . 'x' . $resizedyy . '\!  -compose ' . $mask['blend'] . ' -composite ' . $file2;
                    exec($command);

                    break;

                case "merge":
                    $png = @imagecreatefrompng($_SERVER['DOCUMENT_ROOT'] . $mask['image']);
                    $jpg = @imagecreatefromjpeg($file2);

                    $out = imagecreatetruecolor(imagesx($png), imagesy($png));
                    $color = imagecolorallocate($out, 255, 255, 255);
                    imagefilledrectangle($out, 0, 0, imagesx($out), imagesy($out), $color);

                    imagecopyresampled($out, $jpg, $mask['x'], $mask['y'], 0, 0, imagesx($jpg), imagesy($jpg), imagesx($jpg), imagesy($jpg));
                    imagecopyresampled($out, $png, 0, 0, 0, 0, imagesx($png), imagesy($png), imagesx($png), imagesy($png));
                    imagejpeg($out, $file2, 90);

                    break;
            }
    }
}
