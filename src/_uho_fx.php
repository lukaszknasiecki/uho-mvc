<?php

namespace Huncwot\UhoFramework;

/**
 * This class provides a set of static utility functions
 */

class _uho_fx
{
    private static $initialized = false;

    /**
     * Class constructor
     */
    private static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
    }


    /**
     * Returns trus is Ajax request found
     * @return boolean
     */

    public static function isAjax()
    {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            return true;
        } else return false;
    }

    /**
     * Returns current timestamp in mySQL format
     * @return string
     */

    public static function sqlNow()
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Returns current date in mySQL format
     * @return string
     */

    public static function sqlToday()
    {
        return date('Y-m-d');
    }

    /**
     * Returns secured POST var
     * @param string $PostVar
     * @return string
     */

    public static function securePost($PostVar)
    {
        return addslashes(htmlspecialchars(stripslashes(strip_tags($_POST[$PostVar]))));
    }

    /**
     * Returns secured GET var
     * @param string $GetVar
     * @return string
     */

    public static function secureGet($GetVar)
    {
        return addslashes($GetVar);
    }

    /**
     * Returns secured GET array
     * @return array
     */

    public static function getGetArray()
    {
        if (isset($_SERVER["REQUEST_URI"]))
            $get = explode('?', $_SERVER["REQUEST_URI"]);
            else $get=[];
        if (isset($get[1])) $get = $get[1];
        else $get = '';
        parse_str($get, $get2);
        return $get2;
    }

    /**
     * Returns secured GET var field using REQUEST_URI
     * @param string $param field name
     * @param string $default returns if no value found in GET
     * @return string
     */

    public static function getGet($param, $default = '')
    {
        if (isset($_SERVER['REQUEST_URI']))
            $request = (string) $_SERVER['REQUEST_URI'];
            else $request='';
        if (preg_match('/' . (string) $param . '\=([a-zA-Z0-9\%\_\-\+ ]{1,})/', $request, $request)) {
            return _uho_FX::secureGet(urldecode(strip_tags($request[1])));
        }
        return $default;
    }

    public static function sanitize_input(array $input, array $keys)
    {
        $output = [];

        foreach ($keys  as $k => $v)
            if (isset($input[$k]))
                switch ($v) {
                    case "string":
                        $output[$k] = filter_var($input[$k], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                        break;
                    case "boolean":
                        $output[$k] = filter_var($input[$k], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                        if ($output[$k]=='true') $output[$k]=1; else $output[$k]=0;
                        break;
                    case "url":
                        $output[$k] = filter_var($input[$k], FILTER_SANITIZE_ENCODED);
                        break;
                    case "int":
                        $output[$k] = filter_var($input[$k], FILTER_VALIDATE_INT);
                        break;
                }

        return $output;
    }

    /**
     * Util function replacing array values
     * @param array $array
     * @param array $replace string to please
     * @param string $a1 prefix
     * @param string $a2 suffix
     * @return array
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
     * Adds leading zeros to string
     * @param int $s
     * @param string $ile number of chars in number
     * @return string
     */

    public static function dozeruj($s, $ile)
    {
        if (is_array($s)) return '';
        self::initialize();
        while (strlen($s) < $ile) {
            $s = '0' . $s;
        }
        $s = substr($s, 0, $ile);
        return ($s);
    }

    /**
     * Converts string to og:tg:description format
     * @param string $text text to be converted
     * @param boolean $isHtml
     * @param int $length
     * @param boolean $firstParagraph
     * @param boolean $enters
     * @return string
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

        // longer than 255? let's get sentences
        if (strlen($text) > $length) {
            $sentences = explode('. ', $text);
            $text = '';
            $i = 0;
            while (strlen($text) < $length && $i < count($sentences)) {
                $text .= $sentences[$i] . '. ';
                $i++;
            }
        }

        // longer than 255? let's get words
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
     * Filters array by elements with specified key:value pair
     * @param array $array
     * @param string $key
     * @param string $value
     * @param array $params
     * @return array
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
     * Fills array with key:value pair
     * @param array $array
     * @param string $key
     * @param string $value
     * @return array
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
     * Sorts 2-dimensional array by specific field
     * @param array $data
     * @param string $field
     * @param string $style use SORT_NUMERIC for strings/numbers
     * @param string $lang
     * @param string $sort, SORT_NUMERIC 
     * @return array
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
     * Swaps array keys, [ ['value':1,'label':'label'], ['value':2,'label':'label'] -> [ 1: 'label', 2:'label', ... ]
     * @param array $array
     * @param string $new_key
     * @param string $value
     * @return array
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
     * Extracts values by key from the array
     * @param array $array
     * @param string $key
     * @return array
     */

    public static function array_extract($array, $key, $flip = false)
    {
        $r = [];
        if (is_array($array)) {
            foreach ($array as $v) {
                if ($flip) $r[$v[$key]] = 1;
                else $r[] = $v[$key];
            }
        }
        return $r;
    }

    /**
     * Fills array with patterns [ '%label%','label %1%'] with { 'label'=>'value'} and $params [1=>'value','2=>...']
     * @param array $array
     * @param array $params
     * @return array
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
     * Decaches image path
     * @param string $image
     * @return string
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
     * Chceck if file exists adding full server path
     * @param string $filename
     * @param boolean $skip_query
     * @return boolean
     */

    public static function file_exists($filename, $skip_query = false) : bool
    {
        if ($skip_query) {
            $filename = _uho_fx::image_decache($filename);
        }
        return file_exists(rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $filename);
    }

    /**
     * Gets image size adding full server path
     *
     * @param string $filename
     *
     * @return (int|string)[]|false
     *
     * @psalm-return array{0: int, 1: int, 2: int, 3: string, mime: string, channels?: 3|4, bits?: int}|false
     */
    public static function getimagesize(string $filename): array|false
    {
        return @getimagesize(rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $filename);
    }

    /**
     * Gets image ratio
     *
     * @param string $filename
     *
     * @return float|int|null
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
     * Gets image filesize
     *
     * @param string $filename
     * @param boolean readable
     * @param boolean skip_query
     *
     * @return false|int|string
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
     * Trim right side of the string with given char
     * @param string $s
     * @param string $char
     * @return string
     */

    public static function rtrim($s, $char)
    {
        if (substr($s, strlen($s) - strlen($char)) == $char)
            $s = substr($s, strlen($s) - strlen($char));
        return $s;
    }

    /**
     * convert strftime to dateformat
     * @param string $format
     * @param string $d
     * @return string
     */

    private static function strftime_old($format, $d)
    {
        $ff = ['%A' => 'l', '%B' => 'F', '%b' => 'M'];
        if (isset($ff[$format])) $format = $ff[$format];
        $date = date_create($d);
        return (date_format($date, $format));
    }


    /**
     * @param null|string $lang
     *
     * @psalm-param '%A'|'%B'|'%b' $format
     *
     * @return false|string
     */
    private static function strftime(string $format, string $d, string|null $lang = null): string|false
    {

        $ff = ['%B' => 'MMMM', '%b' => 'MMM'];

        if (function_exists('datefmt_create') && isset($ff[$format])) {
            $format = $ff[$format];
            $date = date_create($d);
            $locale = $lang . '_' . strtoupper($lang) . '.utf-8';

            $fmt = datefmt_create(
                $locale,
                IntlDateFormatter::FULL,
                IntlDateFormatter::FULL,
                date_default_timezone_get(),
                IntlDateFormatter::GREGORIAN,
                $format
            );

            return datefmt_format($fmt, $date);
        } else return _uho_fx::strftime_old($format, $d);
    }


    /**
     * convert single date to multiple formats
     * @param string $date
     * @param string $lang
     * @param string $return_field
     * @return array
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

        if ($return_field) return $r[$return_field];
        else  return $r;
    }

    /**
     * Convert double dates (from-to) to multiple formats
     * @param string $date1
     * @param string $date2
     * @param string $lang
     * @param string $format
     * @return array
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
     * remove szewce (polish verb) from string
     * @param string $value
     * @param boolean $numbers
     * @return string
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
     * Encrypt / Decrypt function
     * @param string $action
     * @param string $string
     * @param array $keys
     * @param string $extra_key
     * @return string
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
     * Encrypt function
     * @param string $string
     * @param array $keys
     * @param string $extra_key
     * @return string
     */

    public static function encrypt($string, $keys, $extra_key = null)
    {
        return _uho_fx::encrypt_decrypt('encrypt', $string, $keys, $extra_key);
    }

    /**
     * Decrypt function
     * @param string $string
     * @param array $keys
     * @param string $extra_key
     * @return string
     */

    public static function decrypt($string, $keys, $extra_key = null)
    {
        return _uho_fx::encrypt_decrypt('decrypt', $string, $keys, $extra_key);
    }

    /**
     * Returns declination type for numbers in Polish
     * @param int $number
     * @return int
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
     * Convert quotes
     * @param string $value
     * @param string $lang
     * @return string
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
     * Remove Local chars
     * @param string $string
     * @param boolean $additional
     * @return string
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
     * Normalize charset
     * @param string $string
     * @param string $filler
     * @return string
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
     * Removes Tags from Text
     *
     * @param string $html
     * @param string $start
     * @param string $end
     *
     * @return string[]
     *
     * @psalm-return list{0?: string,...}
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
     * Halts everything
     *
     * @param string $message
     *
     * @return never
     */
    public static function halt($message)
    {
        exit($message);
    }

    /**
     * Load file via Curl
     * @param string $url
     * @param array $params
     * @return string
     */

    public static function fileCurl($url, $params = null, $data = null, $return_error = false)
    {
        if (!$params) $params = [];
        if (empty($params['timeout'])) $params['timeout'] = 15;

        if (strpos($url, ' --insecure')) {
            $url = str_replace(' --insecure', '', $url);
            $params['verify_host'] = false;
        }

        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, $params['timeout']);

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
            if ($data)
            {
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
     * Curl Util
     * @param string $method='GET|POST|PUT|DELETE'
     * @param string $url
     * @param array|string $data
     * @param array $params
     * @return string
     */

    public static function curl($method='GET',$url='',$data=[],$params=[])
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
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
                break;
            case 'POST':
            default:                
                curl_setopt($ch, CURLOPT_POST, 1);
                if (is_array($data)) $data=json_encode($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        $data = curl_exec($ch);

        curl_close($ch);

        if (!$data) {
            return ['result' => false, 'error' => curl_error($ch)];
        }
        else
        {
            if (isset($params['accept']) && $params['accept'] == 'application/json')
                $data = @json_decode($data, true);
        }

        return ['result'=>true,'data'=>$data];
        
    }

    /**
     * Updates PHP's trim function so it actually works
     * @param string $string
     * @param array $trim
     * @return string
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
     * Updates PHP's trim function so it actually works
     * @param string $string
     * @param array $trim
     * @return string
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
     * Insject element to an array
     * @param array $array
     * @param int $index
     * @param string $element
     * @return array
     */

    public static function array_inject($array, $index, $element)
    {
        $pre = array_slice($array, 0, $index);
        $post = array_slice($array, $index);
        $array = array_merge($pre, [$element], $post);
        return $array;
    }

    /**
     * Fills array with key/value pair
     *
     * @param array $array
     * @param string $key
     * @param string $value
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
     * Load CSV as an object
     *
     * @param string $filename
     *
     * @return null|string[][]
     *
     * @psalm-return list{0?: array<string, string>,...}|null
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
     * Load CSV as an object
     * @param string $filename
     * @return array
     */

    public static function saveCsv($filename, $data, $delimiter = ';')
    {
        $file_to_read = @fopen($filename, 'w');
        if (!$file_to_read) return null;
        foreach ($data as $v)
            fputcsv($file_to_read, $v, $delimiter);

        fclose($file_to_read);
    }

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

    /*
        Converts Spreadsheet (i.e. from Excel)
        Where first row are headers, and data starts from the 2nd row

        ID  First_Name     Last_name
        1   Joe            Doe
        2   Jane           Smith

        Returns
        [
            ['id'=>1,'First_name'=>'Joe','Last_name'=>'Doe],
            ['id'=>2,'First_name'=>'Jane','Last_name'=>'Smith]
        ]
    */
    /**
     * @return array[]
     *
     * @psalm-return list<non-empty-array>
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
}
