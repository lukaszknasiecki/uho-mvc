<?php

namespace Huncwot\UhoFramework;

/**
 * This depreceated class supports randomize function for cached HTMLs
 */

require_once('application/_uho/_uho_fx.php');

/**
 * This is depreceated function
 * @param string $html
 * @return array
 */

function ucr($html)
{
    $section_tag = '<!-- UHO-CHACHE-RANDOMIZER -->';
    $item_tag = '<!-- UCR-LOOP -->';
    $count = 0;

    $end = false;

    $ucr_values = [];

    while (strpos(' ' . $html, $section_tag) && !$end) {
        $i = strpos($html, $section_tag);
        $j = strpos($html, $section_tag, $i + 1);
        if ($j) {
            $items = substr($html, $i + strlen($section_tag), $j - $i - strlen($section_tag));
            if (substr($items, 0, 4) == '<!--') {
                $ii = explode(' ', substr($items, 5));
                if ($ii) {
                    $ii = intval($ii[0]);
                }
            }
            if (!$ii) {
                $ii = 1;
            }
            $items = explode($item_tag, $items);
            array_pop($items); // blank

            foreach ($items as $k => $v) {
                $items[$k] = ['nr' => _uho_fx::dozeruj($k, 4), 'html' => $v];
            }

            if (count($items) > 1) {
                $count++;
                $inside = [];

                while (count($items) > 0 && $ii > 0) {
                    $r = rand(0, count($items) - 1);
                    $ucr_values[] = $r . '/' . count($items);
                    $inside[] = $items[$r];
                    $ii--;
                    unset($items[$r]);
                    $items = array_values($items);
                }
                $inside = _uho_fx::array_multisort($inside, 'nr');
                foreach ($inside as $k => $v) {
                    $inside[$k] = $v['html'];
                }
                $inside = implode('', $inside);
                $html = substr($html, 0, $i) . $inside . substr($html, $j + strlen($section_tag));
            } else {
                $end = true;
            }
        } else {
            $end = true;
        }
    }
    return ['html' => $html, 'count' => $count, 'stats' => implode(',', $ucr_values)];
}
