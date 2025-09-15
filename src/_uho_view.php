<?php

namespace Huncwot\UhoFramework;

/**
 * This is the main View class for the framework, utilizing
 * TWIG templates as base template system
 */


class _uho_view
{
    /**
     * Path to views folder, i.e. application/views
     */
    public $public_url;
    /**
     * Twig instance
     */
    public $twig;
    /**
     * Template filename prefix for this application
     */
    public $template_prefix;
    /**
     * Template subfolder for this application
     */
    public $template_subfolder = '';
    /**
     * Indicates if full HTML should be rendered
     * or only AJAX-able part
     */
    public $renderHtmlRoot = true;
    /**
     * Indicated debug to be rendered
     */
    public $debug = false;
    /**
     * Full path to current application public_html folder
     */
    private $root_path;
    private $lang;
    private $template_path;

    private $prefix = 'view_';
    private $twig_ext = 'html';

    /**
     * Constructor
     * @param string $template_prefix
     * @param string $template_path
     * @param string $public_url
     * @param string $root_path
     * @return null
     */

    public function __construct($template_prefix, $template_path, $public_url, $root_path)
    {
        $this->template_prefix = $template_prefix;
        $this->template_path = $template_path;
        $this->public_url = $public_url;
        $this->root_path = $root_path;
    }

    /**
     * @psalm-param '' $base
     */
    public function renderHtml(string $base = '', array $data = [])
    {
        // render current content
        if (isset($data['content']['head']) && $data['content']['head']) {
            $data['head'] = $data['content']['head'];
        }
        $data['content'] = $this->getContentHtml($data['content'], $data['view']);

        // render whole page
        if ($this->renderHtmlRoot) {
            $html = $this->getTwig($base, $data);
        }
        // or render content only
        else {
            $html = $data['content'];
        }
        return $html;
    }

    /*
        Updates [[sprite::slug]] syntax
    */
    /**
     * @return null|string|string[]
     *
     * @psalm-return array<string>|null|string
     */
    public function renderSprite($html): array|string|null
    {
        $pattern = "/\[\[sprite\::([a-z0-9-_]+)\]\]/";
        $replacement = '<svg class="sprite-$1"><use xlink:href="#sprite-$1"/></svg>';
        return preg_replace($pattern, $replacement, $html);
    }

    /*
        Updates [[svg::slug]] syntax
    */
    public function renderSVG($html)
    {
        $svgs = [];
        $max=1000;
        while ($max && $i = strpos($html, '[[svg::')) {

            $max--;
            $j = strpos($html, ']]', $i);
            $svg = substr($html, $i + 7, $j - $i - 7);
            if (isset($svgs[$svg])) $data = $svgs[$svg];
            else {
                if (strpos($svg, '.svg'))
                    $filename = $_SERVER['DOCUMENT_ROOT'] . $svg;
                else
                    $filename = $_SERVER['DOCUMENT_ROOT'] . '/application/views/svg/' . $svg . '.svg';

                $data = @file_get_contents($filename);
                if (!$data && $this->renderHtmlRoot) echo ('<!-- SVG NOT FOUND::' . $filename . ' ... ' . substr($html, $i, 100) . ' -->');
                $svgs[$svg] = $data;
            }

            $data = str_replace('<svg', '<svg class="svg-' . $svg . '" ', $data);
            $html = substr($html, 0, $i) . $data . substr($html, $j + 2);
        }
        return $html;
    }

    /**
     * Returns HTML for given view
     * @param array $data
     * @return string
     */

    public function getHtml($data)
    {
        return $this->renderHtml('', $data);
    }

    /**
     * Parse data with html via Twig
     * @param array $data
     * @param string $view
     * @return string
     */

    public function getContentHtml($data, $view)
    {
        return $this->getTwig($view, $data);
    }

    /**
     * Parses data with Twig, using additional plugins
     * @param string $class
     * @param array $data
     * @return string
     */

    public function getTwig($class, $data)
    {

        if (!$this->twig) {
            $loader = new \Twig\Loader\FilesystemLoader($this->root_path . 'app' . 'lication/views');
            $this->twig = new \Twig\Environment($loader, array('debug' => $this->debug));

            // adding filters

            // --- base64
            $twig_filter_base64_encode = new \Twig\TwigFilter('base64_encode', function ($string) {
                return (base64_encode($string));
            });

            // --- ucwords
            $twig_filter_ucwords = new \Twig\TwigFilter('ucwords', function ($string, $params) {
                if ($params && $this->lang == 'pl') return (ucfirst($string));
                else return (ucwords($string));
            });

            // --- double-dashes

            $twig_filter_double_dashes = new \Twig\TwigFilter('double_dashes', function ($string) {
                $string = str_replace('—', '&mdash;', $string);
                //$string=str_replace('&mdash;','<span class="emdash">—</span>',$string);
                $string = str_replace('&mdash;', '&mdash;&mdash;', $string);
                return $string;
            });

            // --- nospaces
            $twig_filter_nospaces = new \Twig\TwigFilter('nospaces', function ($string) {
                return (str_replace(' ', '&nbsp;', $string));
            });

            // --- dozeruj
            $twig_filter_dozeruj = new \Twig\TwigFilter('dozeruj', function ($string, $params) {
                return (_uho_fx::dozeruj($string, $params));
            });

            // --- declination
            $twig_filter_declination = new \Twig\TwigFilter('declination', function ($context, $string, $params) {
                // {{count|declination(['obiekt','obiekty','obiektow'],false)}}
                if (is_array($params)) {
                    if (isset($params['number'])) $show_number = $params['number'];
                    else $show_number = true;
                    if (isset($params['words'])) $params = $params['words'];
                    if ($string == 1) $result = 1;
                    elseif ($string != 12 && $string != 13 && $string != 14 && ($string % 10 == 2 || $string % 10 == 3 || $string % 10 == 4)) $result = 2;
                    else $result = 3;
                    if ($show_number)
                        return $string . ' ' . $params[$result - 1];
                    else return $params[$result - 1];
                } else {
                    $params = intval($params);
                    if ($params == 1) $result = 1;
                    elseif ($params != 12 && $params != 13 && $params != 14 && ($params % 10 == 2 || $params % 10 == 3 || $params % 10 == 4)) $result = 2;
                    else $result = 3;
                    return $context['translate'][$string . '_' . $result];
                }
            }, ['needs_context' => true]);


            // --- duration
            $twig_filter_duration = new \Twig\TwigFilter('duration', function ($context, $string, $params = null) {
                $string = intval($string);
                if (isset($params) && @$params['short']) $result = _uho_fx::dozeruj(intval($string / 60) % 60, 2) . ':' . _uho_fx::dozeruj($string % 60, 2);
                else
                if (isset($params) && @$params['type'] == 'hours_if_needed') {
                    $result = _uho_fx::dozeruj(round($string / 60) % 60, 2) . ':' . _uho_fx::dozeruj($string % 60, 2);
                    if ($string > 60 * 60) $result = _uho_fx::dozeruj(round($string / 3600), 2) . ':' . $result;
                } else
                    $result = _uho_fx::dozeruj(floor($string / 3600), 2) . ':' . _uho_fx::dozeruj(intval($string / 60) % 60, 2) . ':' . _uho_fx::dozeruj($string % 60, 2);
                return $result;
            }, ['needs_context' => true]);

            // --- filesize
            $twig_filter_filesize = new \Twig\TwigFilter('filesize', function ($context, $string) {
                $string = intval($string / 1000);
                if ($string < 1000)
                    $result = number_format($string, 0) . 'KB';
                else $result = number_format($string / 1000, 1) . 'MB';
                return $result;
            }, ['needs_context' => true]);

            // --- date_PL
            $twig_filter_date_PL = new \Twig\TwigFilter('date_PL', function ($string) {
                return substr($string, 8, 2) . '.' . substr($string, 5, 2) . '.' . substr($string, 0, 4);
            });

            // --- date
            /*
            $twig_filter_date = new \Twig\TwigFilter('date', function ($context, $string, $params)
            {
                $separator=isset($params['separator']) ? $params['separator'] : '.';
                return substr($string, 8, 2) . $separator . substr($string, 5, 2) . $separator . substr($string, 0, 4);
            });*/
            // --- time
            $twig_filter_time = new \Twig\TwigFilter('time', function ($string) {
                return substr($string, 11, 5);
            });


            $twig_filter_shuffle = new \Twig\TwigFilter('shuffle', function ($array) {
                shuffle($array);
                return $array;
            });

            // --- polish "szewce"
            $twig_filter_szewce = new \Twig\TwigFilter('szewce', function ($string) {
                // spojniki
                $value = preg_replace('/(\s([\w]{1})\s)/u', ' ${2}&nbsp;', $string);

                // spojniki dlugie
                $i = ['II', 'The', 'the', 'dr.', 'Dr.', 'ul.', 'Le', 'La', 'El'];
                foreach ($i as $v) {
                    $value = str_replace(' ' . $v . ' ', ' ' . $v . '&nbsp;', $value);
                }

                // spojniki dlugie
                $i = ['II', 'dr.', 'Dr.', 'ul.'];
                foreach ($i as $$v) {
                    if (substr($value, 0, strlen($v)) == $v) {
                        $value = $v . '&nbsp;' . substr($value, strlen($v) + 1);
                    }
                }

                // wieszaki
                $i = ['r.', 'w.', 'm', 'km', 'tys.', 'mln', 'mld', 'godz.', 'cm', 'kg', 'g', 'min.', 'E)'];

                foreach ($i as $v) {
                    if ($v[strlen($v) - 1] == '.') {
                        $value = str_replace(' ' . $v, '&nbsp;' . $v, $value);
                    }
                    $value = str_replace(' ' . $v . ' ', '&nbsp;' . $v . ' ', $value);
                    $value = str_replace(' ' . $v . ',', '&nbsp;' . $v . ',', $value);
                    $value = str_replace(' ' . $v . '.', '&nbsp;' . $v . '.', $value);
                }

                if (isset($value[strlen($value) - 2]) && $value[strlen($value) - 2] == ' ') {
                    $value = substr($value, 0, strlen($value) - 2) . '&nbsp;' . $value[strlen($value) - 1];
                }

                // spaces to numbers
                if (is_string($value)) {
                    $value = explode(' ', $value);
                }
                for ($a = 1; $a < sizeof($value); $a++) {
                    if (is_numeric($value[$a])) {
                        $value[$a] = '&nbsp;' . $value[$a];
                    } else {
                        $value[$a] = ' ' . $value[$a];
                    }
                }
                $value = implode('', $value);

                return $value;
            });

            // |brackets2tag('strong')
            $twig_filter_brackets2tag = new \Twig\TwigFilter('brackets2tag', function ($context, $string, $params) {
                if (is_array($params)) {
                    return str_replace('[', $params[0], str_replace(']', $params[1], $string));
                }
                return str_replace('[', '<' . $params . '>', str_replace(']', '</' . $params . '>', $string));
            }, ['needs_context' => true]);




            $this->twig->addFilter($twig_filter_declination);
            $this->twig->addFilter($twig_filter_duration);
            $this->twig->addFilter($twig_filter_filesize);

            $this->twig->addFilter($twig_filter_date_PL);
            //$this->twig->addFilter($twig_filter_date);
            $this->twig->addFilter($twig_filter_time);
            $this->twig->addFilter($twig_filter_szewce);
            $this->twig->addFilter($twig_filter_brackets2tag);

            $this->twig->addFilter($twig_filter_nospaces);
            $this->twig->addFilter($twig_filter_dozeruj);
            $this->twig->addFilter($twig_filter_ucwords);
            $this->twig->addFilter($twig_filter_double_dashes);
            $this->twig->addFilter($twig_filter_base64_encode);
            $this->twig->addFilter($twig_filter_shuffle);

            $this->twig->addExtension(new \Twig\Extension\DebugExtension());
            $this->extendTwig($this->twig);
        }

        if (!$class) {
            $class = $this->prefix . $this->template_prefix;
        } elseif (strpos($class, '/'));
        elseif ($class) {
            $pre_class = $this->prefix . $this->template_prefix;
            if ($pre_class) $pre_class .= '_';
            $class = $pre_class . $class;
        }

        if (!$data) {
            $data = array();
        }

        if ($this->template_subfolder) {
            $class = $this->template_subfolder . '/' . $class;
        }

        $html = $this->twig->render($class . '.' . $this->twig_ext, $data);

        return $html;
    }
    //=========================================================================================

    /**
     * Extend base twig to different features per project
     * put your filters, functions in global in directories
     *
     * /application/Twig/Filter
     * /application/Twig/Function
     * /application/Twig/Global
     *
     * @param $twig
     */
    public function extendTwig(\Twig\Environment $twig): void
    {
        $rootScript = $_SERVER['SCRIPT_FILENAME'];
        $parts = explode(DIRECTORY_SEPARATOR, $rootScript);
        array_pop($parts);
        $path = implode(DIRECTORY_SEPARATOR, $parts);
        $path .= '/application/Twig';

        $filterDir = $path . '/Filter';
        $functionDir = $path . '/Function';
        $globalDir = $path . '/Global';

        $twig->addExtension(new \Twig\Extension\StringLoaderExtension());

        // filters
        if (is_dir($filterDir)) {
            foreach (scandir($filterDir) as $file) {
                if ('.' == $file || '..' == $file) {
                    continue;
                }

                $className = 'App\\Twig\\Filter\\' . str_replace('.php', '', $file);
                require_once($filterDir . DIRECTORY_SEPARATOR . $file);
                $filter = new $className;

                $simpleFilter = new \Twig\TwigFilter($filter->getName(), function ($value) use ($filter) {
                    return $filter->filter($value);
                });
                $twig->addFilter($simpleFilter);
            }
        }

        // functions
        if (is_dir($functionDir)) {
            foreach (scandir($functionDir) as $file) {
                if ('.' == $file || '..' == $file) {
                    continue;
                }

                $className = 'App\\Twig\\Functions\\' . str_replace('.php', '', $file);
                require_once($functionDir . DIRECTORY_SEPARATOR . $file);
                $function = new $className;

                $simpleFunction = new \Twig\TwigFunction(
                    $function->getName(),
                    function ($value) use ($function) {
                        return $function->execute($value);
                    },
                    $function->getOptions()
                );
                $twig->addFunction($simpleFunction);
            }
        }

        // globals
        if (is_dir($globalDir)) {
            foreach (scandir($globalDir) as $file) {
                if ('.' == $file || '..' == $file) {
                    continue;
                }

                $className = 'App\\Twig\\Globals\\' . str_replace('.php', '', $file);
                require_once($globalDir . DIRECTORY_SEPARATOR . $file);
                $global = new $className;
                $twig->addGlobal($global->getName(), $global->getValue());
            }
        }
    }

    public function setLang($lang): void
    {
        $this->lang = $lang;
    }
    public function setTemplatePrefix($pre): void
    {
        $this->template_prefix = $pre;
    }
    public function setPrefix($pre): void
    {
        $this->prefix = $pre;
    }

    public function setTwigExt($ext): void
    {
        $this->twig_ext = $ext;
    }
}
