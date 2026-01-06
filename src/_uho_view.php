<?php

namespace Huncwot\UhoFramework;

use Twig\Environment;
use Twig\TwigFilter;
use Twig\Loader\FilesystemLoader;
use Twig\Extension\StringLoaderExtension;

/**
 * Main View class for the framework, utilizing Twig templates as base template system.
 */
class _uho_view
{
    private Environment $twig;

    private string $root_path;
    private string $views_path;
    private string $twig_ext = 'html';
    private string $template_prefix = '';

    private bool $renderHtmlRoot = true;
    private string $lang = '';
    private bool $debug = false;

    /*
        Constructor method.
    */

    public function __construct(
        string $root_path,
        string $views_path
    ) {
        $this->root_path = $root_path;
        $this->views_path = $views_path;
    }

    /*
        Sets current language for view rendering.
    */
    public function setLang(string $lang): void
    {
        $this->lang = $lang;
    }

    /*
        Sets debug mode for view rendering.
    */
    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    public function setRenderHtmlRoot(bool $render): void
    {
        $this->renderHtmlRoot = $render;
    }
    public function getRenderHtmlRoot(): bool
    {
        return $this->renderHtmlRoot;
    }

    public function setTemplatePrefix(string $prefix): void
    {
        $this->template_prefix = $prefix;
    }

    /*
        Changes current twig extension.
    */
    public function setTwigExt(string $ext): void
    {
        $this->twig_ext = $ext;
    }

    /**
     * Renders full HTML page or only content part.
     */
    public function renderHtml(string $base = '', array $data = [], $svg = false): string
    {
        if (isset($data['content']['head']) && $data['content']['head']) {
            $data['head'] = $data['content']['head'];
        }

        $data['content'] = $this->getContentHtml($data['content'], $data['view']);

        $html = $this->renderHtmlRoot
            ? $this->getTwig($base, $data)
            : $data['content'];

        if ($svg) {
            $html = $this->renderSprite($html);
            $html = $this->renderSVG($html);
        }
        return $html;
    }

    /**
     * Returns HTML for base view.
     */
    public function getHtml($data)
    {
        return $this->renderHtml('', $data);
    }

    /**
     * Parse data with html via Twig.
     */
    public function getContentHtml(mixed $data, string $view): string
    {
        return $this->getTwig($view, $data);
    }

    /**
     * Converts [[sprite::slug]] syntax to render SVG elements.
     */
    public function renderSprite(string $html): string
    {
        $pattern = "/\[\[sprite\::([a-z0-9-_]+)\]\]/";
        $replacement = '<svg class="sprite-$1"><use xlink:href="#sprite-$1"/></svg>';
        return preg_replace($pattern, $replacement, $html) ?? $html;
    }

    /**
     * Updates [[svg::slug]] syntax to inline SVG content.
     */
    public function renderSVG(string $html): string
    {
        $svgs = [];
        $max = 1000;

        while ($max > 0 && ($i = strpos($html, '[[svg::')) !== false) {
            $max--;
            $j = strpos($html, ']]', $i);
            $svg = substr($html, $i + 7, $j - $i - 7);

            if (isset($svgs[$svg])) {
                $data = $svgs[$svg];
            } else {
                $filename = str_contains($svg, '.svg')
                    ? $this->root_path . $svg
                    : $this->root_path . $this->views_path . 'svg/' . $svg . '.svg';

                $data = @file_get_contents($filename);

                if (!$data && $this->renderHtmlRoot && $this->debug) {
                    echo '<!-- [DBG] SVG NOT FOUND::' . $filename . ' ... ' . substr($html, $i, 100) . ' -->';
                }

                $svgs[$svg] = $data;
            }

            $data = str_replace('<svg', '<svg class="svg-' . $svg . '" ', $data);
            $html = substr($html, 0, $i) . $data . substr($html, $j + 2);
        }

        return $html;
    }

    /**
     * Parses data with Twig.
     */
    public function getTwig(string $template, mixed $data): string
    {
        if (empty($this->twig)) {
            $this->initializeTwig();
        }

        $data = $data ?: [];
        return $this->twig->render($this->template_prefix . $template . '.' . $this->twig_ext, $data);
    }

    /**
     * Initialize Twig environment and register all filters.
     */

    private function initializeTwig(): void
    {
        $loader = new FilesystemLoader($this->root_path . $this->views_path);
        $this->twig = new Environment($loader, ['debug' => $this->debug]);
        $this->twig->addExtension(new StringLoaderExtension());
        $this->registerTwigFilters();
    }

    /**
     * Register all custom Twig filters.
     */
    private function registerTwigFilters(): void
    {
        $filters = [
            new TwigFilter('base64_encode', fn($string) => base64_encode($string)),

            new TwigFilter('ucwords', function ($string, $params) {
                return ($params && $this->lang === 'pl') ? ucfirst($string) : ucwords($string);
            }),

            new TwigFilter('double_dashes', function ($string) {
                return str_replace('—', '&mdash;&mdash;', $string);
            }),

            new TwigFilter('nospaces', fn($string) => str_replace(' ', '&nbsp;', $string)),

            new TwigFilter('dozeruj', fn($string, $params) => _uho_fx::dozeruj($string, $params)),

            new TwigFilter('declination', function ($context, $string, $params) {
                if (is_array($params)) {
                    $show_number = $params['number'] ?? true;
                    $words = $params['words'] ?? $params;

                    $result = match (true) {
                        $string == 1 => 1,
                        !in_array($string, [12, 13, 14]) && in_array($string % 10, [2, 3, 4]) => 2,
                        default => 3
                    };

                    return $show_number ? "$string {$words[$result - 1]}" : $words[$result - 1];
                }

                $params = intval($params);
                $result = match (true) {
                    $params == 1 => 1,
                    !in_array($params, [12, 13, 14]) && in_array($params % 10, [2, 3, 4]) => 2,
                    default => 3
                };

                return $context['translate'][$string . '_' . $result];
            }, ['needs_context' => true]),

            new TwigFilter('duration', function ($context, $string, $params = null) {
                $string = intval($string);

                if (isset($params['short'])) {
                    return _uho_fx::dozeruj(intval($string / 60) % 60, 2) . ':' . _uho_fx::dozeruj($string % 60, 2);
                }

                if (isset($params['type']) && $params['type'] === 'hours_if_needed') {
                    $result = _uho_fx::dozeruj(round($string / 60) % 60, 2) . ':' . _uho_fx::dozeruj($string % 60, 2);
                    return $string > 3600 ? _uho_fx::dozeruj(round($string / 3600), 2) . ':' . $result : $result;
                }

                return _uho_fx::dozeruj(floor($string / 3600), 2) . ':'
                    . _uho_fx::dozeruj(intval($string / 60) % 60, 2) . ':'
                    . _uho_fx::dozeruj($string % 60, 2);
            }, ['needs_context' => true]),

            new TwigFilter('filesize', function ($context, $string) {
                $kb = intval($string / 1000);
                return $kb < 1000
                    ? number_format($kb, 0) . 'KB'
                    : number_format($kb / 1000, 1) . 'MB';
            }, ['needs_context' => true]),

            new TwigFilter(
                'date_PL',
                fn($string) =>
                substr($string, 8, 2) . '.' . substr($string, 5, 2) . '.' . substr($string, 0, 4)
            ),

            new TwigFilter('time', fn($string) => substr($string, 11, 5)),

            new TwigFilter('shuffle', function ($array) {
                shuffle($array);
                return $array;
            }),

            new TwigFilter('szewce', fn($string) => $this->applySzewce($string)),

            new TwigFilter('brackets2tag', function ($context, $string, $params) {
                if (is_array($params)) {
                    return str_replace(['[', ']'], [$params[0], $params[1]], $string);
                }
                return str_replace(['[', ']'], ["<$params>", "</$params>"], $string);
            }, ['needs_context' => true]),
        ];

        foreach ($filters as $filter) {
            $this->twig->addFilter($filter);
        }
    }

    /**
     * Apply Polish typography filter (szewce).
     */
    private function applySzewce(string $string): string
    {
        // Single letter conjunctions
        $value = preg_replace('/(\s([\w]{1})\s)/u', ' ${2}&nbsp;', $string);

        // Long conjunctions
        $longConjunctions = ['II', 'The', 'the', 'dr.', 'Dr.', 'ul.', 'Le', 'La', 'El'];
        foreach ($longConjunctions as $conjunction) {
            $value = str_replace(' ' . $conjunction . ' ', ' ' . $conjunction . '&nbsp;', $value);
        }

        // Line-starting conjunctions
        $startConjunctions = ['II', 'dr.', 'Dr.', 'ul.'];
        foreach ($startConjunctions as $conjunction) {
            if (str_starts_with($value, $conjunction)) {
                $value = $conjunction . '&nbsp;' . substr($value, strlen($conjunction) + 1);
            }
        }

        // Units and abbreviations
        $units = ['r.', 'w.', 'm', 'km', 'tys.', 'mln', 'mld', 'godz.', 'cm', 'kg', 'g', 'min.', 'E)'];
        foreach ($units as $unit) {
            if (str_ends_with($unit, '.')) {
                $value = str_replace(' ' . $unit, '&nbsp;' . $unit, $value);
            }
            $value = str_replace(
                [' ' . $unit . ' ', ' ' . $unit . ',', ' ' . $unit . '.'],
                ['&nbsp;' . $unit . ' ', '&nbsp;' . $unit . ',', '&nbsp;' . $unit . '.'],
                $value
            );
        }

        // Last character non-breaking space
        if (strlen($value) >= 2 && $value[strlen($value) - 2] === ' ') {
            $value = substr($value, 0, -2) . '&nbsp;' . $value[strlen($value) - 1];
        }

        // Numbers with non-breaking spaces
        $parts = explode(' ', $value);
        for ($i = 1; $i < count($parts); $i++) {
            $parts[$i] = is_numeric($parts[$i]) ? '&nbsp;' . $parts[$i] : ' ' . $parts[$i];
        }

        return implode('', $parts);
    }

    /*
        Deprecated methods below.
    */
    public function setPrefix(string $prefix): void {}
}
