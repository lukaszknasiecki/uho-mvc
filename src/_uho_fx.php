<?php

namespace Huncwot\UhoFramework;

/**
 * This class provides a set of static utility functions
 *
 * Available Functions:
 *
 * Array Operations:
 * - array_change_keys($array, $new_key, $value) - Change array keys by using field value
 * - array_extract($array, $key, $flip) - Extract values by key from array
 * - array_fill_key(&$array, $key, $value) - Fill array with key/value pair by reference
 * - array_filter($array, $key, $value, $params) - Filter array by key:value pair
 * - array_inject($array, $index, $element) - Inject element into array at index
 * - array_multi_fill($array, $key, $value) - Fill array with key:value pair
 * - array_multisort($data, $field, $style, $lang, $sort) - Sort 2D array by specific field
 * - array_remove_keys($array, $keys_to_remove) - Remove keys from array (multidimensional)
 * - arrayReplace($array, $replace, $a1, $a2) - Replace array values with patterns
 *
 * Date/Time:
 * - convertSingleDate($date, $lang, $return_field) - Convert date to multiple formats
 * - getDate($date1, $date2, $lang, $format) - Convert single or double dates to multiple formats
 * - sqlNow() - Get current timestamp in MySQL format
 * - sqlToday() - Get current date in MySQL format
 *
 * Encryption:
 * - decrypt($string, $keys, $extra_key) - Decrypt string
 * - encrypt($string, $keys, $extra_key) - Encrypt string
 * - encrypt_decrypt($action, $string, $keys, $extra_key) - Encrypt or decrypt string
 *
 * File Operations:
 * - file_exists($filename, $skip_query) - Check if file exists (with server path)
 * - filesize($filename, $readable, $skip_query) - Get file size
 * - getimagesize($filename) - Get image size (with server path)
 * - image_decache($image) - Remove cache parameters from image path
 * - image_ratio($filename) - Get image aspect ratio
 * - loadCsv($filename, $delimiter) - Load CSV as array of objects
 * - remote_file_exists($filename, $skip_query) - Check if remote file exists
 * - saveCsv($filename, $data, $delimiter) - Save array to CSV file
 *
 * HTTP/CURL:
 * - curl($method, $url, $data, $params) - CURL utility for REST operations
 * - fileCurl($url, $params, $data, $return_error) - Load file via CURL
 *
 * Request/Response:
 * - getGet($param, $default) - Get specific GET parameter from REQUEST_URI
 * - getGetArray() - Parse and return GET array from REQUEST_URI
 * - isAjax() - Check if request is Ajax
 * - sanitize_input($input, $keys) - Sanitize input data based on type specifications
 * - secureGet($GetVar) - Sanitize GET variable
 * - securePost($PostVar) - Sanitize POST variable
 *
 * String Operations:
 * - charsetNormalize($string, $filler) - Normalize charset for URLs
 * - dozeruj($s, $ile) - Add leading zeros to string
 * - excludeTagsFromText($html, $start, $end) - Extract content between tags
 * - fillPattern($array, $params) - Fill patterns with values
 * - headDescription($text, $isHtml, $length, $firstParagraph, $enters) - Create og:description format
 * - mb_trim($string, $trim, $charset) - Multibyte trim function
 * - quotes($value, $lang) - Convert quotes to language-specific format
 * - removeLocalChars($string, $additional) - Remove local/special characters
 * - rtrim($s, $char) - Trim right side with specific character
 * - szewce($value, $numbers) - Remove orphans (Polish typography)
 * - trim($string, $trim) - Enhanced trim function
 *
 * Utilities:
 * - convertSpreadsheet($items) - Convert spreadsheet data to array of objects
 * - dec2dms($latitude, $longitude) - Convert decimal coordinates to DMS format
 * - halt($message) - Exit script with message
 * - microtime_float() - Get current microtime as float
 * - resolveRoute($queryString, $routing) - Resolve request path to handler class
 * - utilsNumberDeclinationPL($number) - Get Polish number declination type
 */

class _uho_fx
{
    private static $initialized = false;

    /**
     * Initialize the class (called once)
     *
     * @return void
     */
    private static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
    }


    /**
     * Check if the current request is an Ajax request
     *
     * @return bool True if Ajax request detected, false otherwise
     */
    public static function isAjax()
    {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            return true;
        } else return false;
    }

    /**
     * Get current timestamp in MySQL format (Y-m-d H:i:s)
     *
     * @return string Current timestamp in MySQL format
     */
    public static function sqlNow()
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Get current date in MySQL format (Y-m-d)
     *
     * @return string Current date in MySQL format
     */
    public static function sqlToday()
    {
        return date('Y-m-d');
    }

    /**
     * Sanitize and return a POST variable
     *
     * @param string $PostVar The POST variable name
     * @return string Sanitized POST value
     */
    public static function securePost($PostVar)
    {
        return addslashes(htmlspecialchars(stripslashes(strip_tags($_POST[$PostVar]))));
    }

    /**
     * Sanitize and return a GET variable
     *
     * @param string $GetVar The GET variable value to sanitize
     * @return string Sanitized GET value
     */
    public static function secureGet($GetVar)
    {
        return addslashes($GetVar);
    }

    /**
     * Parse and return GET parameters array from REQUEST_URI
     *
     * @return array Associative array of GET parameters
     */
    public static function getGetArray()
    {
        if (isset($_SERVER["REQUEST_URI"]))
            $get = explode('?', $_SERVER["REQUEST_URI"]);
        else $get = [];
        if (isset($get[1])) $get = $get[1];
        else $get = '';
        parse_str($get, $get2);
        return $get2;
    }

    /**
     * Get a specific GET parameter from REQUEST_URI
     *
     * @param string $param Field name to retrieve
     * @param string $default Default value if parameter not found
     * @return string Sanitized GET parameter value or default
     */
    public static function getGet($param, $default = '')
    {
        if (isset($_SERVER['REQUEST_URI']))
            $request = (string) $_SERVER['REQUEST_URI'];
        else $request = '';
        if (preg_match('/' . (string) $param . '\=([a-zA-Z0-9\%\_\-\+ ]{1,})/', $request, $request)) {
            return _uho_FX::secureGet(urldecode(strip_tags($request[1])));
        }
        return $default;
    }

    /**
     * Sanitize input data based on specified keys and their types
     *
     * Supported types: string, enum, email, date, array, array_int, point, bbox,
     * boolean, url, int, integer, any
     *
     * @param array $input Input data to sanitize
     * @param array $keys Array mapping field names to their expected types
     * @return array Sanitized output array
     */
    public static function sanitize_input(array $input, array $keys)
    {
        $output = [];

        foreach ($keys  as $k => $v) {
            if (is_array($v) && isset($input[$k])) {
                $output[$k] = [];
                foreach ($input[$k] as $kk => $vv)
                    $output[$k][$kk] = _uho_fx::sanitize_input($vv, $v[0]);
            } else
            if (isset($input[$k]))
                switch ($v) {
                    case "string":
                        $output[$k] = htmlspecialchars(strip_tags($input[$k]), ENT_NOQUOTES, 'UTF-8');
                        break;
                    case "enum":
                        $output[$k] = htmlspecialchars(strip_tags($input[$k]), ENT_NOQUOTES, 'UTF-8');
                        break;
                    case "email":

                        $sanitized_a = filter_var($input[$k], FILTER_SANITIZE_EMAIL);
                        if (filter_var($sanitized_a, FILTER_VALIDATE_EMAIL))
                            $output[$k] = $sanitized_a;

                        break;
                    case "date":
                        $pattern = "/^\d{4}-\d{2}-\d{2}$/"; // YYYY-MM-DD
                        if (filter_var($input[$k], FILTER_VALIDATE_REGEXP, ["options" => ["regexp" => $pattern]]))
                            $output[$k] = $input[$k];
                        break;

                    case "array":
                        if (is_array($input[$k]))
                            $output[$k] = $input[$k];
                        break;
                    case "array_int":
                        if (is_array($input[$k])) {
                            foreach ($input[$k] as $k2 => $v2)
                                $input[$k][$k2] = intval($v2);
                            $output[$k] = $input[$k];
                        }
                        break;
                    case "point":
                        $arr = explode(',', $input[$k]);
                        $arr = array_filter($arr, 'is_numeric');
                        $output[$k] = count($arr) == 2 ? implode(',', $arr) : null;

                        break;
                    case "bbox":
                        $arr = explode(',', $input[$k]);
                        $arr = array_filter($arr, 'is_numeric');
                        $output[$k] = count($arr) == 4 ? implode(',', $arr) : null;
                        break;
                    case "boolean":
                        $output[$k] = filter_var($input[$k], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                        if ($output[$k] == 'true') $output[$k] = 1;
                        else $output[$k] = 0;
                        break;
                    case "url":
                        $output[$k] = filter_var($input[$k], FILTER_SANITIZE_ENCODED);
                        break;
                    case "int":
                    case "integer":
                        $output[$k] = filter_var($input[$k], FILTER_VALIDATE_INT);
                        break;
                    case "any":
                        $output[$k] = $input[$k];
                        break;
                    default:
                        if (isset($output[$k])) unset($output[$k]);
                        break;
                }
        }

        return $output;
    }

    /**
     * Replace array values using pattern matching
     *
     * Replaces occurrences of {prefix}{key}{suffix} with corresponding values
     *
     * @param array|string $array Array or string to process
     * @param array $replace Associative array of replacements (key => value)
     * @param string $a1 Prefix for pattern matching
     * @param string $a2 Suffix for pattern matching
     * @return array|string Array or string with replaced values
     */
    public static function arrayReplace($array, $replace, $a1, $a2)
    {
        if (is_array($array) && is_array($replace)) {
            foreach ($array as $k => $v) {
                if (is_array($v)) {
                    $array[$k] = _uho_FX::arrayReplace($v, $replace, $a1, $a2);
                } else {
                    foreach ($replace as $k2 => $v2)
                        if (is_string($v2)) {
                            $array[$k] = str_replace($a1 . $k2 . $a2, $v2, $array[$k]);
                        }
                }
            }
        } else {
            foreach ($replace as $k2 => $v2) {
                $array = str_replace($a1 . $k2 . $a2, $v2, $array);
            }
        }
        return $array;
    }

    /**
     * Add leading zeros to a string or number
     *
     * @param int|string $s Value to pad with zeros
     * @param int $ile Desired total length
     * @return string Zero-padded string
     */
    public static function dozeruj($s, $ile)
    {
        if (!is_string($s) && !is_numeric($s)) return '';
        self::initialize();
        while (strlen($s) < $ile) {
            $s = '0' . $s;
        }
        $s = substr($s, 0, $ile);
        return ($s);
    }

    /**
     * Convert text to og:description/meta description format
     *
     * Creates a clean, truncated description suitable for meta tags and social media
     *
     * @param string $text Text to convert
     * @param bool $isHtml Whether the input is HTML
     * @param int $length Maximum length of output
     * @param bool $firstParagraph Extract only first paragraph if HTML
     * @param bool $enters Remove line breaks
     * @return string Formatted description text
     */
    public static function headDescription($text, $isHtml = false, $length = 255, $firstParagraph = true, $enters = true)
    {
        if ($isHtml && $firstParagraph) {
            // getting first paragraph...
            $text = explode('</p>', $text);
            $text = $text[0];
            $text = strip_tags($text);
        } elseif ($isHtml) {
            $text = strip_tags($text);
            $text = html_entity_decode($text);
        }

        if ($enters)
            $text = str_replace(chr(13) . chr(10), ' ', $text);

        // longer than length? let's get sentences
        $text = trim($text);
        if (strlen($text) > $length) {
            $sentences = explode('. ', $text);
            $text = '';
            $i = 0;
            while (strlen($text) < $length && $i < count($sentences)) {
                $text .= $sentences[$i] . '. ';
                $i++;
            }
        }

        // longer than length? let's get words
        if (strlen($text) > $length) {
            $words = explode(' ', $text);
            $text = '';
            $i = 0;
            while (strlen($text) < $length  && $i < count($words)) {
                $text .= $words[$i] . ' ';
                $i++;
            }
            $text .= '...';
        }

        return $text;
    }

    /**
     * Filter array by elements with specified key:value pair
     *
     * Supported params: 'first' (return first match only), 'returnField' (return specific field),
     * 'keys' (return keys only), 'search' (use strpos search), 'strict' (strict comparison),
     * 'case' (case sensitivity)
     *
     * @param array $array Array to filter
     * @param string $key Key to search for
     * @param mixed $value Value to match (null to check key existence)
     * @param array|null $params Additional filtering parameters
     * @return array|mixed Filtered array or single value if 'first' param used
     */
    public static function array_filter($array, $key, $value = null, $params = null)
    {
        $firstOnly = (@$params['first'] ? true : false);
        $resultField = @$params['returnField'];
        $returnKeys = (@$params['keys'] ? true : false);
        $strPosSearch = (@$params['search'] ? true : false);
        $strict = (@$params['strict'] ? true : false);
        $case = true;
        if (@$params['case'] === false) $params['case'] = false;

        $result = array();
        if (is_array($array)) {
            foreach ($array as $k => $v) {
                // array value
                if (is_array($value)) {
                    $ok = true;
                    foreach ($value as $k2 => $v2) {
                        if (isset($v[$key][$k2]) && $v[$key][$k2] != $v2) {
                            $ok = false;
                        }
                    }
                } else {
                    $ok = false;
                }

                if (
                    (!isset($key))
                    || $ok
                    || (isset($value) && @$v[$key] == $value)
                    || (isset($value) && is_string($value) && $case && !$strict && isset($v[$key]) && is_string($v[$key]) && strtolower(@$v[$key]) == strtolower($value))
                    || (isset($value) && $strPosSearch && strpos(' ' . $v[$key], $value) == 1)
                    || (!isset($value) && isset($v[$key]))
                ) {
                    if ($returnKeys) {
                        $result[$k] = $k;
                    } else {
                        $result[$k] = $v;
                    }
                }
            }
        }
        if ($firstOnly) {
            $result = array_shift($result);
            if ($resultField) {
                $result = $result[$resultField];
            }
        } elseif ($resultField && is_array($result)) {
            foreach ($result as $k => $v) {
                $result[$k] = $v[$resultField];
            }
        }
        return ($result);
    }

    /**
     * Fill all elements in array with the same key:value pair
     *
     * @param array $array Array to fill
     * @param string $key Key to add to each element
     * @param mixed $value Value to set for the key
     * @return array Modified array with added key:value pairs
     */
    public static function array_multi_fill($array, $key, $value)
    {
        if (is_array($array)) {
            foreach ($array as $k => $_) {
                $array[$k][$key] = $value;
            }
        }
        return $array;
    }


    /**
     * Sort 2-dimensional array by a specific field
     *
     * @param array $data Array to sort
     * @param string $field Field name to sort by
     * @param int $style Sort style (SORT_ASC or SORT_DESC)
     * @param string $lang Language for locale-specific sorting (e.g., 'pl')
     * @param int $sort Sort type (SORT_LOCALE_STRING, SORT_NUMERIC, etc.)
     * @return array Sorted array
     */
    public static function array_multisort($data, $field, $style = SORT_ASC, $lang = "", $sort = SORT_LOCALE_STRING)
    {
        $surname = array();
        $surname2 = array();
        if ($data) {
            foreach ($data as $key => $v)
                if (isset($v[$field])) {
                    $surname[$key] = $v[$field];
                    $surname2[$key] = $v[$field];
                } else {
                }
        }

        if ($lang == 'pl') {
            setlocale(LC_ALL, "pl_PL.UTF-8");
        }

        sort($surname, $sort);

        foreach ($surname2 as $k => $v) {
            $id = array_search($v, $surname);
            $surname2[$k] = $id;
        }

        if ($data) {
            try {
                if (count($surname2) == count($data))
                    array_multisort($surname2, $style, $data);
            } catch (Exception $e) {
            }
        }
        return ($data);
    }

    /**
     * Change array keys by using a field value as the new key
     *
     * Example: [['value'=>1, 'label'=>'A'], ['value'=>2, 'label'=>'B']]
     *       -> [1 => 'A', 2 => 'B'] (or [1 => ['value'=>1, 'label'=>'A'], ...])
     *
     * @param array $array Source array
     * @param string $new_key Field to use as new key
     * @param string|null $value Field to use as value (null to keep entire element)
     * @return array Array with changed keys
     */
    public static function array_change_keys($array, $new_key, $value = null)
    {
        $result = [];
        foreach ($array as $v) {
            $key = $v[$new_key];
            if ($value) $v = $v[$value];
            $result[$key] = $v;
        }
        return $result;
    }

    /**
     * Remove specified keys from array (works with multidimensional arrays)
     *
     * Example: array_remove_keys($items, ['id', 'uid', 'slug', 'image' => ['original']])
     *
     * @param array $array Source array
     * @param array $keys_to_remove Keys to remove (can be nested)
     * @return array Array with specified keys removed
     */
    public static function array_remove_keys(array $array, array $keys_to_remove): array
    {
        if (is_array($array)) {
            // array
            if (array_values($array) == $array) {
                foreach ($array as $key => $v)
                    foreach ($keys_to_remove as $key_r => $key_to_remove)
                        if (is_array($key_to_remove)) {
                            $array[$key][$key_r] = _uho_fx::array_remove_keys($array[$key][$key_r], $key_to_remove);
                        } else {
                            if (isset($array[$key][$key_to_remove]))
                                unset($array[$key][$key_to_remove]);;
                        }
            } else
            // object
            {
                foreach ($keys_to_remove as $key_r => $key_to_remove)
                    if (is_array($key_to_remove)) {
                        $array[$key_r] = _uho_fx::array_remove_keys($array[$key_r], $key_to_remove);
                    } else
                    if (isset($array[$key_to_remove]))
                        unset($array[$key_to_remove]);
            }
        }
        return $array;
    }

    /**
     * Extract values for a specific key from array elements
     *
     * @param array $array Source array
     * @param string $key Key to extract from each element
     * @param bool $flip If true, use extracted values as keys with value 1
     * @return array Array of extracted values
     */
    public static function array_extract($array, $key, $flip = false)
    {
        $r = [];
        if (is_array($array)) {
            foreach ($array as $v) {
                if ($flip) $r[$v[$key]] = 1;
                elseif (isset($v[$key])) $r[] = $v[$key];
            }
        }
        return $r;
    }

    /**
     * Fill patterns in array with values
     *
     * Replaces patterns like '%label%' or '%1%' with values from params
     *
     * @param array|string $array Array or string containing patterns
     * @param array $params Array with 'keys' and/or 'numbers' for replacement values
     * @return array|string Array or string with patterns replaced
     */
    public static function fillPattern($array, $params)
    {
        if (!is_array($array)) {
            $string = true;
            $array = [$array];
        }

        foreach ($array as $k => $v) {
            if ($params['numbers'])
                foreach ($params['numbers'] as $k2 => $v2)
                    if ($k2) $array[$k] = str_replace('%' . $k2 . '%', $v2, $array[$k]);
            if ($params['keys'])
                foreach ($params['keys'] as $k2 => $v2)
                    $array[$k] = str_replace('%' . $k2 . '%', $v2, $array[$k]);
        }
        if ($string) $array = $array[0];
        return $array;
    }

    /**
     * Remove cache parameters from image path
     *
     * Removes query strings and cache busting suffixes (___) from image paths
     *
     * @param string $image Image path with potential cache parameters
     * @return string Clean image path
     */
    public static function image_decache($image)
    {
        if (is_string($image)) {
            $image = explode('?', $image);
            $image = array_shift($image);
            $image = explode('.', $image);
            $ext = array_pop($image);
            $base = implode('.', $image);
            $base = explode('___', $base);
            $base = array_shift($base);
            $image = $base . '.' . $ext;
        }
        return $image;
    }

    /**
     * Check if file exists (adds full server path)
     *
     * @param string $filename File path relative to document root
     * @param bool $skip_query Remove query parameters before checking
     * @return bool True if file exists, false otherwise
     */
    public static function file_exists($filename, $skip_query = false): bool
    {
        if ($skip_query) {
            $filename = _uho_fx::image_decache($filename);
        }
        return file_exists(rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $filename);
    }

    /**
     * Check if remote file exists via HTTP headers
     *
     * @param string $filename Remote file URL
     * @param bool $skip_query Remove query parameters before checking
     * @return bool True if file returns HTTP 200, false otherwise
     */
    public static function remote_file_exists($filename, $skip_query = false): bool
    {
        if ($skip_query) {
            $filename = _uho_fx::image_decache($filename);
        }
        $headers = @get_headers($filename);
        if ($headers && strpos($headers[0], '200') !== false) return true;
        else  return false;
    }

    /**
     * Get image size (adds full server path)
     *
     * @param string $filename Image path relative to document root
     * @return array|false Array with image dimensions and info, or false on failure
     * @psalm-return array{0: int, 1: int, 2: int, 3: string, mime: string, channels?: 3|4, bits?: int}|false
     */
    public static function getimagesize(string $filename): array|false
    {
        return @getimagesize(rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $filename);
    }

    /**
     * Get image aspect ratio (width/height)
     *
     * @param string $filename Image path relative to document root
     * @return float|int|null Aspect ratio or null if image not found
     */
    public static function image_ratio($filename): int|float|null
    {
        $filename = _uho_fx::image_decache($filename);
        $ratio = @getimagesize(rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $filename);
        if (isset($ratio[1]) && $ratio[1]) $ratio = $ratio[0] / $ratio[1];
        else $ratio = null;
        return $ratio;
    }

    /**
     * Get file size (adds full server path)
     *
     * @param string $filename File path relative to document root
     * @param bool $readable Return human-readable format (KB/MB)
     * @param bool $skip_query Remove query parameters before checking
     * @return int|string|false File size in bytes, formatted string, or false on failure
     */
    public static function filesize($filename, $readable = false, $skip_query = true): int|string|false
    {
        if ($skip_query) {
            $filename = _uho_fx::image_decache($filename);
        }
        $size = @filesize(rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $filename);
        if ($size && $readable) {
            if ($size < 1000) $size = number_format($size / 1000, 2) . 'KB';
            elseif ($size < (1000 * 1000)) $size = number_format($size / 1000, 0) . 'KB';
            else $size = number_format($size / 1000 / 1000, 2) . 'MB';
        }
        return $size;
    }

    /**
     * Trim right side of string with specific character/string
     *
     * @param string $s String to trim
     * @param string $char Character or string to remove from the end
     * @return string Trimmed string
     */
    public static function rtrim($s, $char)
    {
        if (substr($s, strlen($s) - strlen($char)) == $char)
            $s = substr($s, strlen($s) - strlen($char));
        return $s;
    }

    /**
     * Convert strftime format to date format (legacy fallback)
     *
     * @param string $format strftime format code
     * @param string $d Date string
     * @return string Formatted date
     */
    private static function strftime_old($format, $d)
    {
        $ff = ['%A' => 'l', '%B' => 'F', '%b' => 'M'];
        if (isset($ff[$format])) $format = $ff[$format];
        $date = date_create($d);
        return (date_format($date, $format));
    }


    /**
     * Convert strftime format to date format with locale support
     *
     * @param string $format strftime format code ('%A', '%B', '%b')
     * @param string $d Date string
     * @param string|null $lang Language code for locale-specific formatting
     * @return string|false Formatted date or false on failure
     * @psalm-param '%A'|'%B'|'%b' $format
     */
    private static function strftime(string $format, string $d, string|null $lang = null): string|false
    {

        $ff = ['%B' => 'MMMM', '%b' => 'MMM'];

        if (function_exists('datefmt_create') && isset($ff[$format]))
        {
            $format = $ff[$format];
            $date = date_create($d);
            $locale = $lang . '_' . strtoupper($lang) . '.utf-8';

            $fmt = datefmt_create(
                $locale,
                0, //IntlDateFormatter::FULL,
                0, //IntlDateFormatter::FULL,
                date_default_timezone_get(),
                1, //IntlDateFormatter::GREGORIAN,
                $format
            );

            return datefmt_format($fmt, $date);
        } else return _uho_fx::strftime_old($format, $d);
    }


    /**
     * Convert single date to multiple format variations
     *
     * Returns array with various date formats (sql, short, long, time variants, etc.)
     *
     * @param string $date Date string in SQL format (Y-m-d H:i:s)
     * @param string $lang Language code ('pl', 'en', etc.)
     * @param string|false $return_field Specific field to return, or false for full array
     * @return array|string Array of formatted dates or specific field value
     */
    public static function convertSingleDate($date, $lang = 'pl', $return_field = false)
    {
        $monthsPL1 = explode(';', 'styczeń;luty;marzec;kwiecień;maj;czerwiec;lipiec;sierpień;wrzesień;październik;listopad;grudzień');
        $weekShort = array(
            'pl' => array('ND', 'PN', 'WT', 'ŚR', 'CZW', 'PT', 'SOB', 'ND'),
            'en' => array('Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun')
        );
        $monthsPL2 = explode(';', 'stycznia;lutego;marca;kwietnia;maja;czerwca;lipca;sierpnia;września;października;listopada;grudnia');

        $time = substr($date, 11, 5);
        $time = str_replace('.', ':', $time);
        $time = str_replace('::', ':', $time);
        $time = str_replace('::', ':', $time);
        if ($time) {
            $time = explode(':', $time);
        }
        if ($time) {
            $time = _uho_fx::dozeruj($time[0], 2) . ':' . _uho_fx::dozeruj($time[1], 2);
        }

        if (!$time);
        elseif ($lang == 'pl') {
            $time[2] = '.';
        } else {
            $time[2] = ':';
        }

        $day = intval(substr($date, 8, 2));
        $month = intval(substr($date, 5, 2));
        $weekday_short = @$weekShort[$lang][date('N', strtotime($date))];

        if ($lang == 'pl' && isset($monthsPL1[$month - 1])) {
            $month_txt = $month_txt_short = $monthsPL1[$month - 1];
        } else {
            $month_txt = _uho_fx::strftime('%B', ($date), $lang);
            $month_txt_short = _uho_fx::strftime('%b', ($date), $lang);
        }

        $month_txt_single = $month_txt;
        $month_txt = _uho_fx::strftime('%B', ($date), $lang);


        $r = array(
            'sql' => substr($date, 0, 10),
            'sql_time' => $date,
            'sql_time_T' => str_replace(' ', 'T', $date),
            'datetime' => $date,
            'month' => $month,
            'month_txt' => $month_txt_single,
            'month_txt_short' => _uho_fx::strftime('%b', ($date), $lang),
            'day' => $day,
            'year' => substr($date, 0, 4),
            'short' => substr($date, 8, 2) . '.' . substr($date, 5, 2) . '.' . substr($date, 0, 4),
            'short_no_zero' => intval(substr($date, 8, 2)) . '.' . intval(substr($date, 5, 2)) . '.' . substr($date, 0, 4),
            'short_no_year' => substr($date, 8, 2) . '.' . substr($date, 5, 2),
            'short_time' => substr($date, 8, 2) . '.' . substr($date, 5, 2) . '.' . substr($date, 0, 4) . ' ' . $time,
            'time' => $time,
            'time_proper' => $time,
            'long' => $day . ' ' . _uho_fx::strftime('%B', ($date), $lang) . ' ' . substr($date, 0, 4),
            'long_short_month' => $day . ' ' . $month_txt_short . ' ' . substr($date, 0, 4),
            'long_no_day' => $month_txt . ' ' . substr($date, 0, 4),
            'long_no_day_short_month' => $month_txt_short . ' ' . substr($date, 0, 4),

            'long_no_year' => $day . ' ' . _uho_fx::strftime('%B', ($date), $lang),
            'long_date' => $day . ' ' . _uho_fx::strftime('%B', ($date), $lang) . ' ' . substr($date, 0, 4),
            'long_date_digits' => substr($date, 8, 2) . '.' . substr($date, 5, 2) . '.' . substr($date, 0, 4),
            'long_date_digits_no_year' => substr($date, 8, 2) . '.' . substr($date, 5, 2),
            'long_weekday_digits' => substr($date, 8, 2) . '.' . substr($date, 5, 2) . ' (' . $weekday_short . ')',
            'long_time' => $day . ' ' . _uho_fx::strftime('%B', ($date), $lang) . ' ' . substr($date, 0, 4) . ', godz. ' . $time,
            'long_time_noyear' => $day . ' ' . _uho_fx::strftime('%B', ($date), $lang) . ', ' . $time,
            'long_time_digits' => substr($date, 8, 2) . '.' . substr($date, 5, 2) . ' ' . $time,
            'long_time_digits_godz' => substr($date, 8, 2) . '.' . substr($date, 5, 2) . ' godz. ' . $time,
            'long_time_weekday_digits' => substr($date, 8, 2) . '.' . substr($date, 5, 2) . ' (' . $weekday_short . ') ' . $time,
            'long_time_weekday_digits_year' => substr($date, 8, 2) . '.' . substr($date, 5, 2) . '.' . substr($date, 0, 4) . ' (' . $weekday_short . ') ' . $time,
            'day02' => _uho_fx::dozeruj(substr($date, 8, 2), 2),
            'weekday_short' => $weekday_short,
            'weekday_nr' => date('N', strtotime($date)),
            'weekday' => _uho_fx::strftime('%A', ($date), $lang)
        );


        $r['long_date_digits_nozero'] = str_replace('.0', '.', $r['long_date_digits']);
        $r['long_date_digits_nozero'] = ltrim($r['long_date_digits_nozero'], '0');

        $r['long_no_year_proper'] = $r['long_no_year'];

        if ($lang == 'en') {
            $r['long_no_year_proper'] = _uho_fx::strftime('%B', ($date), $lang) . ' ' . $day;
            $r['long_no_year_short_month'] = $r['long_no_year_proper_short_month'] = _uho_fx::strftime('%b', ($date), $lang) . ' ' . $day;
        }

        if ($lang == 'en') {
            if ($r['time']) {
                $t = explode(':', $r['time']);
                $ampm = 'am';

                if ($t[0] == 12) {
                    $ampm = 'pm';
                } elseif ($t[0] > 12) {
                    $t[0] -= 12;
                    $ampm = 'pm';
                }
                $r['time_proper'] = implode(':', $t) . ' ' . $ampm;
            }
            $r['long'] = _uho_fx::strftime('%B', ($date), $lang) . ' ' . $day . ', ' . substr($date, 0, 4);
            $r['long_short_month'] = _uho_fx::strftime('%b', ($date), $lang) . ' ' . $day . ', ' . substr($date, 0, 4);
            $r['long_short_month_no_year'] = _uho_fx::strftime('%b', ($date), $lang) . ' ' . $day;
            $r['long_no_year'] = _uho_fx::strftime('%B', ($date), $lang) . ' ' . $day;
        }

        if ($lang != 'pl') {
            $r['long_time'] = _uho_fx::strftime('%B', ($date), $lang) . ' ' . $day . ' ' . substr($date, 0, 4) . ', ' . $time;
            $r['long_time_noyear'] = _uho_fx::strftime('%B', ($date), $lang) . ' ' . $day . ', ' . $time;
            $r['long_time_digits_godz'] = substr($date, 8, 2) . '.' . substr($date, 5, 2) . ' ' . $time;
        }

        if ($lang == 'pl') {
            $r['long'] = str_replace($monthsPL1, $monthsPL2, $r['long']);
            $r['long_no_year'] = str_replace($monthsPL1, $monthsPL2, $r['long_no_year']);
            $r['long_date'] = str_replace($monthsPL1, $monthsPL2, $r['long_date']);
            $r['long_time'] = str_replace($monthsPL1, $monthsPL2, $r['long_time']);
            $r['long_time_noyear'] = str_replace($monthsPL1, $monthsPL2, $r['long_time_noyear']);
        }

        if ($return_field && isset($r[$return_field])) return $r[$return_field];
            elseif ($return_field) return "";
            else return $r;
    }

    /**
     * Convert single or double dates (date ranges) to multiple format variations
     *
     * Handles both single dates and date ranges (from-to) with smart formatting
     *
     * @param string $date1 First/main date in SQL format
     * @param string|null $date2 End date for ranges (optional)
     * @param string $lang Language code ('pl', 'en', etc.)
     * @param string|null $format Specific format to return, or null for full array
     * @return array|string Array of formatted dates or specific format value
     */

    public static function getDate($date1, $date2 = null, $lang = 'pl', $format = null)
    {
        
        $years_same = (substr($date1, 0, 4) == substr($date2, 0, 4));
        $months_same = (substr($date1, 0, 7) == substr($date2, 0, 7));
        $date01 = $date1;
        $date1 = _uho_fx::convertSingleDate($date1, $lang);

        // double date
        if ($date2 && $date2[0] != '0' && substr($date1['sql'], 0, 10) != substr($date2, 0, 10)) {
            if ($date1['time_proper']) {
                $time = ', ' . $date1['time_proper'];
            }
            $i1 = 'long_no_year_proper';
            $i2 = 'long_no_year_proper';
            if (substr($date1['sql'], 0, 4) == substr($date2, 0, 4)) {
                $i1 = 'long_no_year_proper';
            }

            $date2 = _uho_fx::convertSingleDate($date2, $lang);

            $date1['day02'] = $date1['day02'] . '–' . $date2['day02'];

            if ($months_same) {
                $date1['long_no_year'] = $date1[$i1] . '–' . $date2['day'];
                if (isset($date1['long_no_year_proper_short_month']))
                    $date1['long_no_year_short_month'] = $date1['long_no_year_proper_short_month'] . '–' . $date2['day'];
            } else {
                $date1['long_no_year'] = $date1[$i1] . '–' . $date2[$i2];
                if (isset($date1['long_no_year_proper_short_month']))
                    $date1['long_no_year_short_month'] = $date1['long_no_year_proper_short_month'] . '–' . $date2['long_no_year_proper_short_month'];
            }

            if ($lang == 'pl') {
                $date1['long'] = $date1['long_no_year'] . ' ' . $date1['year'];
            } else {
                if ($date1['year'] != $date2['year']) {
                    $date1['long'] = $date1['long_no_year_proper'] . ', ' . $date1['year'] . ' – ' . $date2['long_no_year_proper'] . ', ' . $date2['year'];
                } else {
                    $date1['long'] = $date1['long_no_year'] . ', ' . $date1['year'];
                }
            }

            $time = isset($time) ? $time : '';
            $date1['long_time'] = $date1['long_no_year'] . $time;

            $date1['month_year'] = $date1['month_txt'] . ' ' . $date1['year'];
            $date1['month_short_year'] = $date1['month_txt_short'] . ' ' . $date1['year'];


            if (!$years_same) {
                $date1['long_date_digits'] = $date1['short'] . ' – ' . $date2['short'];
            } elseif ($months_same) {
                $date1['long_date_digits'] = $date1['day'] . '–' . $date2['day'] . '.' . _uho_fx::dozeruj($date1['month'], 2) . '.' . $date1['year'];
                $date1['long_date_digits_no_year'] = $date1['day'] . '–' . $date2['day'] . '.' . _uho_fx::dozeruj($date1['month'], 2);
            } else {
                $date1['long_date_digits'] = $date1['short_no_year'] . '–' . $date2['short'];
                $date1['long_date_digits_no_year'] = $date1['short_no_year'] . '–' . $date2['short_no_year'];
            }

            $date1['long_time_digits'] = $date1['short_no_year'] . ' – ' . $date2['short'] . @$time;

            $date1['long_time_noyear'] = $date1[$i1] . ' – ' . $date2[$i2] . @$time;
            if ($months_same) {
                $date1['long_no_time'] = $date1[$i1] . ' – ' . $date2['day'] . ', ' . $date1['year'];
                $date1['long_no_time_no_year'] = $date1[$i1] . ' – ' . $date2['day'];
            } else {
                $date1['long_no_time_no_year'] = $date1['long_no_time'] = $date1[$i1] . ' – ' . $date2[$i2];
            }

            $date1['long_date_digits_nozero'] = str_replace('.0', '.', $date1['long_date_digits']);
            $date1['long_date_digits_nozero'] = ltrim($date1['long_date_digits_nozero'], '0');

            // short
            if (substr($date1['short'], 6, 4) == substr($date2['short'], 6, 4)) {
                $date1['short'] = substr($date1['short'], 0, 5) . '–' . $date2['short'];
            } else {
                $date1['short'] = $date1['short'] . '–' . $date2['short'];
            }
            //else $date1['short_no_year']=$date1['short_no_year'].'–'.$date2['short_no_year'];

            // short_no_year
            if (substr($date1['short_no_year'], 3, 2) == substr($date2['short_no_year'], 3, 2)) {
                $date1['short_no_year'] = substr($date1['short_no_year'], 0, 2) . '–' . $date2['short_no_year'];
            } else {
                $date1['short_no_year'] = $date1['short_no_year'] . '–' . $date2['short_no_year'];
            }

            $date = $date1;
        }

        // end double date

        else {
            if (substr($date01, 11, 5) != substr($date2, 11, 5)) {
                $time2 = '-' . substr($date2, 11, 5);
                $date1['long_time'] .= $time2;
                $date1['long_time_digits'] .= $time2;
                $date1['long_time_weekday_digits'] .= $time2;
                $date1['long_time_weekday_digits_year'] .= $time2;
            }
            $date = $date1;
        }

        $date['multiday'] = ($date2 != null && is_string($date2) && @substr($date2, 0, 4) != '0000');

        $date['multimonth'] = (is_array($date2) && array_key_exists('sql', $date2) && substr($date1['sql'], 0, 7) != substr($date2['sql'], 0, 7));

        if ($format) {
            return $date[$format];
        }

        return $date;
    }

    /**
     * Remove orphans from text (Polish typography - non-breaking spaces)
     *
     * Prevents single-letter words from appearing at line ends
     *
     * @param string $value Text to process
     * @param bool $numbers Apply to numbers as well
     * @return string Text with orphans removed
     */
    public static function szewce($value, $numbers = false)
    {
        if (!$value) return $value;
        $value = preg_replace('/(\s([\w]{1})\s)/u', ' ${2}&nbsp;', $value);

        $i = ['r.', 'w.', 'm', 'km', 'tys.', 'mln', 'mld', 'godz.', 'cm', 'kg', 'g'];

        foreach ($i as $v) {
            if ($v[strlen($v) - 1] == '.') {
                $value = str_replace(' ' . $v, '&nbsp;' . $v, $value);
            }
            $value = str_replace(' ' . $v . ' ', '&nbsp;' . $v . ' ', $value);
            $value = str_replace(' ' . $v . ',', '&nbsp;' . $v . ',', $value);
            $value = str_replace(' ' . $v . '.', '&nbsp;' . $v . '.', $value);
        }


        if ($numbers) {
            $value = explode(' ', $value);
            for ($a = 1; $a < sizeof($value); $a++) {
                if (is_numeric($value[$a])) {
                    $value[$a] = '&nbsp;' . $value[$a];
                } else {
                    $value[$a] = ' ' . $value[$a];
                }
            }
            $value = implode('', $value);
        }
        return ($value);
    }

    /**
     * Encrypt or decrypt a string using AES-256-CBC
     *
     * @param string $action Action to perform: 'encrypt' or 'decrypt'
     * @param string $string String to encrypt/decrypt
     * @param array $keys Array with encryption keys [0] => key, [1] => iv
     * @param string|null $extra_key Optional additional key for encryption
     * @return string|false Encrypted/decrypted string or false on failure
     */
    public static function encrypt_decrypt($action, $string, $keys, $extra_key = null)
    {
        if (!$string) return $string;
        if ($extra_key) $secret_key = 'q' . $keys[0] . $extra_key;
        else $secret_key = 'q' . $keys[0] . '2';
        $secret_iv = '4' . $keys[1] . 'x';

        $output = false;
        $encrypt_method = "AES-256-CBC";
        // hash
        $key = hash('sha256', $secret_key);

        // iv - encrypt method AES-256-CBC expects 16 bytes - else you will get a warning
        $iv = substr(hash('sha256', $secret_iv), 0, 16);
        if ($action == 'encrypt') {
            $output = openssl_encrypt($string, $encrypt_method, $key, 0, $iv);
            $output = base64_encode($output);
        } elseif ($action == 'decrypt') {
            $output = openssl_decrypt(base64_decode($string), $encrypt_method, $key, 0, $iv);
        }
        return $output;
    }

    /**
     * Encrypt a string using AES-256-CBC
     *
     * @param string $string String to encrypt
     * @param array $keys Array with encryption keys [0] => key, [1] => iv
     * @param string|null $extra_key Optional additional key for encryption
     * @return string|false Encrypted string or false on failure
     */
    public static function encrypt($string, $keys, $extra_key = null)
    {
        return _uho_fx::encrypt_decrypt('encrypt', $string, $keys, $extra_key);
    }

    /**
     * Decrypt a string using AES-256-CBC
     *
     * @param string $string String to decrypt
     * @param array $keys Array with encryption keys [0] => key, [1] => iv
     * @param string|null $extra_key Optional additional key for decryption
     * @return string|false Decrypted string or false on failure
     */
    public static function decrypt($string, $keys, $extra_key = null)
    {
        return _uho_fx::encrypt_decrypt('decrypt', $string, $keys, $extra_key);
    }

    /**
     * Get Polish number declination type (1, 2, or 3)
     *
     * Used for proper plural forms in Polish language
     *
     * @param int $number Number to check
     * @return int Declination type: 1 (singular), 2 (few), 3 (many)
     */
    public static function utilsNumberDeclinationPL($number)
    {
        if ($number == 1) {
            $result = 1;
        } elseif ($number % 10 == 2 || $number % 10 == 3 || $number % 10 == 4) {
            $result = 2;
        } else {
            $result = 3;
        }
        return $result;
    }

    /**
     * Convert quotes to language-specific typographic quotes
     *
     * @param string $value Text with quotes
     * @param string $lang Language code ('pl', etc.)
     * @return string Text with converted quotes
     */
    public static function quotes($value, $lang = 'pl')
    {
        if ($lang == 'pl') {
            $value = str_replace('&quot;', '"', $value);
            $value = preg_replace('#(\s"|^")([^"]+)"#', ' „$2”', $value);
        }
        return ($value);
    }


    /**
     * Remove local/special characters and convert to ASCII
     *
     * Converts accented characters to their ASCII equivalents
     *
     * @param string $string String with special characters
     * @param bool $additional Apply additional processing (lowercase, trim, strip tags)
     * @return string String with ASCII characters only
     */
    public static function removeLocalChars($string, $additional = false)
    {

        $table = array(
            'Š' => 'S',
            'š' => 's',
            'Đ' => 'Dj',
            'đ' => 'dj',
            'Ž' => 'Z',
            'ž' => 'z',
            'Č' => 'C',
            'č' => 'c',
            'Ć' => 'C',
            'ć' => 'c',
            'ą' => 'a',
            'ę' => 'e',
            'ł' => 'l',
            'ń' => 'n',
            'ś' => 's',
            'ó' => 'o',
            'ż' => 'z',
            'ź' => 'z',
            'À' => 'A',
            'Á' => 'A',
            'Â' => 'A',
            'Ã' => 'A',
            'Ä' => 'A',
            'Å' => 'A',
            'Æ' => 'A',
            'Ç' => 'C',
            'È' => 'E',
            'É' => 'E',
            'Ê' => 'E',
            'Ë' => 'E',
            'Ì' => 'I',
            'Í' => 'I',
            'Î' => 'I',
            'Ï' => 'I',
            'Ñ' => 'N',
            'Ò' => 'O',
            'Ó' => 'O',
            'Ô' => 'O',
            'Õ' => 'O',
            'Ö' => 'O',
            'Ø' => 'O',
            'Ù' => 'U',
            'Ú' => 'U',
            'Û' => 'U',
            'Ü' => 'U',
            'Ý' => 'Y',
            'Þ' => 'B',
            'ß' => 'Ss',
            'à' => 'a',
            'á' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'ä' => 'a',
            'å' => 'a',
            'æ' => 'a',
            'ç' => 'c',
            'è' => 'e',
            'é' => 'e',
            'ê' => 'e',
            'ì' => 'i',
            'í' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ð' => 'o',
            'ñ' => 'n',
            'ò' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ö' => 'o',
            'ø' => 'o',
            'ù' => 'u',
            'ú' => 'u',
            'û' => 'u',
            'ý' => 'y',
            'þ' => 'b',
            'ÿ' => 'y',
            'Ŕ' => 'R',
            'ŕ' => 'r',
            'Ł' => 'l',
            'Á' => 'A',
            'À' => 'A',
            'Â' => 'A',
            'Ã' => 'A',
            'Å' => 'A',
            'Ä' => 'A',
            'Æ' => 'AE',
            'Ç' => 'C',
            'É' => 'E',
            'È' => 'E',
            'Ê' => 'E',
            'Ì' => 'I',
            'Î' => 'I',
            'Ï' => 'I',
            'Ð' => 'Eth',
            'Ñ' => 'N',
            'Ó' => 'O',
            'Ò' => 'O',
            'Ô' => 'O',
            'Õ' => 'O',
            'Ö' => 'O',
            'Ø' => 'O',
            'Ú' => 'U',
            'Ù' => 'U',
            'Û' => 'U',
            'Ü' => 'U',
            'Ý' => 'Y',
            'á' => 'a',
            'à' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'å' => 'a',
            'ä' => 'a',
            'æ' => 'ae',
            'ç' => 'c',
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'í' => 'i',
            'ì' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ð' => 'eth',
            'ñ' => 'n',
            'ó' => 'o',
            'ò' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ö' => 'o',
            'ø' => 'o',
            'ú' => 'u',
            'ù' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'ý' => 'y',
            'ß' => 'sz',
            'þ' => 'thorn',
            'ÿ' => 'y'
        );

        if ($additional) {
            $string = trim(mb_strtolower($string, "UTF-8"));
            $string = strip_tags($string);
        }
        $string = strtr($string, $table);
        $string = trim($string);

        return $string;
    }


    /**
     * Normalize string for URLs and slugs
     *
     * Converts to lowercase, removes special chars, replaces spaces with filler
     *
     * @param string $string String to normalize
     * @param string $filler Character to replace spaces (default: '-')
     * @return string URL-safe normalized string
     */
    public static function charsetNormalize($string, $filler = '-')
    {

        $string = _uho_fx::removeLocalChars($string, false);
        $table = array(
            ' ' => '-',
            '&' => 'and',
            '#' => '-',
            '>' => '-',
            '<' => '-',
            '"' => '',
            "'" => '',
            ',' => '',
            '(' => '-',
            ')' => '-',
            '“' => '',
            '’' => '',
            '”' => '',
            '—' => '-',
            ' ' => ''
        );

        $string = str_replace(' ', $filler, $string);

        $string = trim(mb_strtolower($string, "UTF-8"));
        $string = strip_tags($string);
        $string = strtr($string, $table);
        $string = str_replace('/', $filler, $string);
        $string = str_replace('\\', $filler, $string);
        $string = str_replace('--', $filler, $string);
        $string = str_replace('--', $filler, $string);

        // final clearance
        $string = preg_replace("/[^a-zA-Z0-9\/_|+ -]/", '', $string);
        $string = preg_replace("/[\/_|+ -]+/", $filler, $string);
        $string = trim($string, $filler);

        for ($i = 0; $i < 10; $i++) {
            $string = str_replace($filler . $filler, $filler, $string);
        }

        return $string;
    }

    /**
     * Extract content between delimiter tags from text
     *
     * @param string $html Text containing delimited sections
     * @param string $start Start delimiter (default: '%')
     * @param string $end End delimiter (default: '%')
     * @return array Array of extracted text segments
     */
    public static function excludeTagsFromText($html, $start = '%', $end = '%'): array
    {
        $i = 0;
        $result = [];
        while (strpos(' ' . $html, $start, $i)) {
            $i1 = strpos($html, $start, $i + 1);
            $i2 = strpos($html, $start, $i1 + 1);
            if (!$i2) $i2 = strlen($html) - 1;
            $result[] = substr($html, $i1 + strlen($start), $i2 - $i1 - strlen($start) - strlen($end) + 1);
            $i = $i2 + 1;
        }
        return $result;
    }

    /**
     * Halt script execution with a message
     *
     * @param string $message Message to display before exit
     * @return never
     */
    public static function halt($message)
    {
        exit($message);
    }

    /**
     * Load file via CURL with various options
     *
     * @param string $url URL to fetch
     * @param array|null $params CURL options (timeout, headers, post, put, delete, etc.)
     * @param mixed $data Data to send with request
     * @param bool $return_error Whether to return error information
     * @return string|array Response data or error array
     */
    public static function fileCurl($url, $params = null, $data = null, $return_error = false)
    {
        if (!$params || !is_array($params)) $params = [];
        if (empty($params['timeout'])) $params['timeout'] = 15;

        if (strpos($url, ' --insecure')) {
            $url = str_replace(' --insecure', '', $url);
            $params['verify_host'] = false;
        }

        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, $params['timeout']);
        
        if (isset($params['follow_location'])) 
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        

        $header = [];
        if (isset($params['accept'])) $header[] = 'accept: ' . $params['accept'];
        if (isset($params['json']))   $header[] = 'accept: application/json';
        if (isset($params['content-type']))   $header[] = 'content-type: ' . $params['content-type'];
        if (isset($params['authorization'])) $header[] = 'Authorization: ' . $params['authorization'];
        if (isset($params['bearer'])) $header[] = 'Authorization: Bearer ' . $params['bearer'];
        if (isset($params['header'])) $header = array_merge($header, $params['header']);

        if (isset($params['gzip']))   curl_setopt($ch, CURLOPT_ENCODING, "gzip"); //$header[]='Accept-encoding: gzip';
        if (isset($params['user_agent']))   curl_setopt($ch, CURLOPT_USERAGENT, $params['user_agent']); //$header[]='Accept-encoding: gzip';
        if ($header) curl_setopt($ch, CURLOPT_HTTPHEADER, $header);

        if (isset($params['verify_host']) && $params['verify_host'] === false) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        }

        if (isset($params['post']) && $data) {

            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        if (isset($params['put'])) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            if ($data) {
                if (is_string($data)) $data = json_decode($data, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            }
        }
        if (isset($params['delete'])) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        }


        $data = curl_exec($ch);

        curl_close($ch);

        if (!$data) {
            return ['result' => false, 'error' => curl_error($ch)];
        }

        if ($data && isset($params['json']) && !empty($params['decode'])) $data = @json_decode($data, true);

        return $data;
    }

    /**
     * CURL utility for REST API operations
     *
     * @param string $method HTTP method: 'GET', 'POST', 'PUT', 'PATCH', 'DELETE'
     * @param string $url URL to request
     * @param array|string $data Request data
     * @param array $params Additional CURL options (timeout, headers, etc.)
     * @return array Result array with 'result', 'data', 'code', and optionally 'error'
     */
    public static function curl($method = 'GET', $url = '', $data = [], $params = [])
    {

        $params['timeout'] = !empty($params['timeout']) ? $params['timeout'] : 30;

        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, $params['timeout']);

        $header = [];
        if (isset($params['accept'])) $header[] = 'accept: ' . $params['accept'];
        if (isset($params['content-type']))   $header[] = 'content-type: ' . $params['content-type'];
        if (isset($params['authorization'])) $header[] = 'Authorization: ' . $params['authorization'];
        if (isset($params['bearer'])) $header[] = 'Authorization: Bearer ' . $params['bearer'];

        if (isset($params['verify_host']) && $params['verify_host'] === false) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        }

        if ($header) curl_setopt($ch, CURLOPT_HTTPHEADER, $header);

        switch ($method) {
            case 'GET':
                curl_setopt($ch, CURLOPT_HTTPGET, 1);
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
                if (is_string($data)) $data = json_decode($data, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                break;
            case 'PATCH':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
                if (is_array($data)) $data = json_encode($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
                break;
            case 'POST':
            default:
                curl_setopt($ch, CURLOPT_POST, 1);
                if (is_array($data)) $data = json_encode($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$data) {
            return ['result' => false, 'error' => curl_error($ch), 'code' => $code];
        } else {
            if (isset($params['accept']) && $params['accept'] == 'application/json')
                $data = @json_decode($data, true);
        }

        return ['result' => true, 'data' => $data, 'code' => $code];
    }

    /**
     * Enhanced trim function for specific strings (not just characters)
     *
     * @param string $string String to trim
     * @param string $trim String to remove from both ends
     * @return string Trimmed string
     */
    public static function trim($string, $trim)
    {
        $max = 100;
        if (!$string) return '';
        while (substr($string, 0, strlen($trim)) == $trim && $max) {
            $string = substr($string, strlen($trim));
            $max--;
        }

        while (substr($string, strlen($string) - strlen($trim), strlen($trim)) == $trim && $max) {
            $max--;
            $string = substr($string, strlen($string) - strlen($trim));
        }
        return $string;
    }

    /**
     * Multibyte-safe enhanced trim function for specific strings
     *
     * @param string $string String to trim
     * @param string $trim String to remove from both ends
     * @param string $charset Character encoding (default: 'UTF-8')
     * @return string Trimmed string
     */
    public static function mb_trim($string, $trim, $charset = 'UTF-8')
    {
        $max = 100;
        if (!$string) return '';
        while (mb_substr($string, 0, mb_strlen($trim, $charset), $charset) == $trim && $max) {
            $string = mb_substr($string, mb_strlen($trim, $charset), null, $charset);
            $max--;
        }

        while (mb_substr($string, mb_strlen($string, $charset) - mb_strlen($trim, $charset), mb_strlen($trim, $charset), $charset) == $trim && $max) {
            $max--;
            $string = mb_substr(
                $string,
                0,
                mb_strlen($string, $charset) - mb_strlen($trim, $charset),
                $charset
            );
        }
        return $string;
    }
    /**
     * Inject an element into an array at a specific index
     *
     * @param array $array Source array
     * @param int $index Position to insert element
     * @param mixed $element Element to insert
     * @return array Array with injected element
     */
    public static function array_inject($array, $index, $element)
    {
        $pre = array_slice($array, 0, $index);
        $post = array_slice($array, $index);
        $array = array_merge($pre, [$element], $post);
        return $array;
    }

    /**
     * Fill array elements with key/value pair (by reference)
     *
     * @param array $array Array to modify (passed by reference)
     * @param string|array $key Key to add (can be array for nested keys)
     * @param mixed $value Value to set
     * @return void
     */
    public static function array_fill_key(&$array, $key, $value): void
    {
        if (is_array($array))
            foreach ($array as $k => $_) {
                if (is_array($key)) $array[$k][$key[0]][$key[1]] = $value;
                else $array[$k][$key] = $value;
            }
    }

    /**
     * Load CSV file as an array of associative arrays
     *
     * First row is used as keys, subsequent rows as values
     *
     * @param string $filename Path to CSV file
     * @param string $delimiter CSV delimiter (default: ';')
     * @return array|null Array of rows as associative arrays, or null on failure
     */
    public static function loadCsv($filename, $delimiter = ';'): array|null
    {
        $file_to_read = @fopen($filename, 'r');
        if (!$file_to_read) return null;
        $lines = [];

        while (!feof($file_to_read)) {
            $lines[] = fgetcsv($file_to_read, 100000, $delimiter);
        }
        fclose($file_to_read);
        if (!$lines) return null;
        $keys = array_shift($lines);

        $data = [];
        foreach ($lines as $v) {
            $item = [];
            foreach ($keys as $kk => $vv)
                if (isset($v[$kk]))
                    $item[$vv] = $v[$kk];
            $data[] = $item;
        }

        return $data;
    }

    /**
     * Save array data to CSV file
     *
     * @param string $filename Path to CSV file
     * @param array $data Array of rows to save
     * @param string $delimiter CSV delimiter (default: ';')
     * @return void
     */
    public static function saveCsv($filename, $data, $delimiter = ';')
    {
        $file_to_read = @fopen($filename, 'w');
        if (!$file_to_read) return null;
        foreach ($data as $v)
            fputcsv($file_to_read, $v, $delimiter);

        fclose($file_to_read);
    }

    /**
     * Convert decimal coordinates to Degrees Minutes Seconds (DMS) format
     *
     * @param float $latitude Latitude in decimal format
     * @param float $longitude Longitude in decimal format
     * @return string Coordinates in DMS format
     */
    public static function dec2dms($latitude, $longitude): string
    {
        $latitudeDirection = $latitude < 0 ? 'S' : 'N';
        $longitudeDirection = $longitude < 0 ? 'W' : 'E';

        $latitudeNotation = $latitude < 0 ? '-' : '';
        $longitudeNotation = $longitude < 0 ? '-' : '';

        $latitudeInDegrees = floor(abs($latitude));
        $longitudeInDegrees = floor(abs($longitude));

        $latitudeDecimal = abs($latitude) - $latitudeInDegrees;
        $longitudeDecimal = abs($longitude) - $longitudeInDegrees;

        $_precision = 3;
        $latitudeMinutes = round($latitudeDecimal * 60, $_precision);
        $longitudeMinutes = round($longitudeDecimal * 60, $_precision);

        return sprintf(
            '%s%s° %s %s %s%s° %s %s',
            $latitudeNotation,
            $latitudeInDegrees,
            $latitudeMinutes,
            $latitudeDirection,
            $longitudeNotation,
            $longitudeInDegrees,
            $longitudeMinutes,
            $longitudeDirection
        );
    }

    /**
     * Convert spreadsheet data (first row as headers) to array of objects
     *
     * Example input:
     *   [['ID', 'First_Name', 'Last_name'], [1, 'Joe', 'Doe'], [2, 'Jane', 'Smith']]
     * Example output:
     *   [['ID'=>1, 'First_Name'=>'Joe', 'Last_name'=>'Doe'], ['ID'=>2, 'First_Name'=>'Jane', 'Last_name'=>'Smith']]
     *
     * @param array $items Spreadsheet data with headers in first row
     * @return array Array of associative arrays
     */
    public static function convertSpreadsheet($items): array
    {
        $cols = array_shift($items);
        $result = [];
        foreach ($cols as $k => $v) if (!$v) unset($cols[$k]);

        foreach ($items as $v) {
            $row = [];
            $empty = true;
            foreach ($cols as $kk => $vv) {
                $row[$vv] = $v[$kk];
                if ($v[$kk]) $empty = false;
            }
            if ($row && !$empty) $result[] = $row;
        }
        return array_values($result);
    }

    /**
     * Resolve request path to handler class and extract route parameters
     *
     * Matches URL patterns with placeholders like "/projects/{id}/download"
     *
     * @param string $queryString Raw request URI (e.g., "/projects/123/download?x=1")
     * @param array $routing Map of "pattern" => "class" (e.g., "/projects/{id}" => "ProjectHandler")
     * @return array|null Array with 'class' and 'params' keys, or null if no match
     */
    public static function resolveRoute(string $queryString, array $routing): ?array
    {
        $path = parse_url($queryString, PHP_URL_PATH) ?? '';
        $path = trim($path, '/');

        $best = null;

        foreach ($routing as $pattern => $class) {
            $normPattern = trim($pattern, '/');
            $segments    = $normPattern === '' ? [] : explode('/', $normPattern);
            $literalCount = 0;

            $regexParts = [];
            foreach ($segments as $seg) {
                if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $seg, $m)) {
                    // placeholder {name}
                    $name = $m[1];
                    $regexParts[] = '(?P<' . $name . '>[^/]+)';
                } else {
                    // literal: escape safely using '~' as delimiter
                    $literalCount++;
                    $regexParts[] = preg_quote($seg, '~');
                }
            }

            // use ~ as delimiter instead of /
            $regex = '~^' . implode('/', $regexParts) . '$~';

            if ($normPattern === '') {
                if ($path !== '') continue;
                $matches = [];
            } else {
                if (!preg_match($regex, $path, $matches)) {
                    continue;
                }
            }

            $params = [];
            foreach ($matches as $k => $v) {
                if (!is_int($k)) {
                    $params[$k] = urldecode($v);
                }
            }

            $score = $literalCount * 1000 + count($segments);

            if ($best === null || $score > $best['score']) {
                $best = [
                    'class'  => $class,
                    'params' => $params,
                    'score'  => $score,
                ];
            }
        }

        return $best ? ['class' => $best['class'], 'params' => $best['params']] : null;
    }

    /**
     * Get current microtime as float
     *
     * @return float Current microtime as float value
     */
    public static function microtime_float() : float
    {
        list($usec, $sec) = explode(" ", microtime());
        return ((float)$usec + (float)$sec);
    }
}
