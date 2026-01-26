<?php

/*
    This class extends _uho_model with website pages methods,
    using standarized 'pages' and 'pages_modules' models

    Public Methods:
    ---------------
    getContentData($params = null)          - Gets Page model based on params.url
    setPathModules($path)                   - Set path to load modules classes

    setParentVar($key, $value)              - Sets a global variable accessible by all modules
    getParentVar($key)                      - Gets a global variable set by modules

    set404()                                - Triggers 404 page response
    is404()                                 - Returns whether 404 flag is set

    ogGet()                                 - Returns header/meta data for sharing (title, description, image)
    ogSet($title, $description, $image)     - Sets header/meta data for sharing
*/

namespace Huncwot\UhoFramework;

use Huncwot\UhoFramework\_uho_fx;
use Huncwot\UhoFramework\_uho_model;

class _uho_model_pages extends _uho_model
{
    private $is404;
    private $path_modules = '';
    private $parent_vars = [];

    var $head = [
        'app_title' => '',
        'image' => ''
    ];

    /*
		Gets Page model based on params.url
	*/

    public function getContentData($params = null)
    {

        $url = empty($params['url']) ? "/" : $params['url'];
        $urlArr = explode('/', $url);

        /*
			Get page by URL
		*/
        if (!$this->is404) {

            $page = $this->findPage($url);
            if (!empty($page)) {
                $this->ogSet($page['title'], $page['description'], $page['image']['medium']);
                $page = $this->updatePage($page, $urlArr, $params['get']);
            }
        }

        /*
			Get 404 page
		*/
        if (empty($page) || empty($page['modules']) || $this->is404) {
            header("HTTP/1.0 404 Not Found");
            $page = $this->get('pages', ['path' => '404'], true);
            if (!$page) exit('Page not found');
            $page = $this->updatePage($page, $urlArr, $params['get']);
        }

        return $page;
    }

    /*
		Finds page by url pattern, i.e. "/news", "/news/%", "news/%;news/%/%"
	*/

    private function findPage($path)
    {
        $page = null;

        $path = explode('/', $path);
        if (empty($path[0])) $path = ['home'];

        $q = 'SELECT id,path FROM pages WHERE active=1 && (path LIKE ? || path LIKE ?)';
        $p = $this->sql->queryPrepared($q, [
            ['s', '%;' . $path[0] . '%'],
            ['s', $path[0] . '%']
        ]);

        /*
			Check for closest page to the pattern
		*/

        $pages = [];

        foreach ($p as $k => $v) {
            $vv = explode(';', $v['path']);
            foreach ($vv as $k2 => $v2)
                if ($v2) $pages[] = ['id' => $v['id'], 'path' => explode('/', $v2), 'power' => 0];
        }


        foreach ($pages as $k => $v) {
            if (count($path) != count($v['path'])) unset($pages[$k]);
            else
                foreach ($path as $k2 => $v2)
                    if ($pages[$k]) {

                        $power = 0;
                        if ($v['path'][$k2] == $v2) $power = 10;
                        elseif ($v['path'][$k2] == '%') $power = 3;
                        else {
                            unset($pages[$k]);
                        }
                        if ($power) $pages[$k]['power'] += $power;
                    }
        }

        if ($pages) {
            $pages = _uho_fx::array_multisort($pages, 'power', SORT_ASC, "", SORT_NUMERIC);
            $page = array_pop($pages);
            $page = $this->get('pages', array('id' => $page['id']), true);
        }

        return $page;
    }

    /*
		Adds modules to the page
	*/
    private function updatePage($page, $urlArr, $getArr)
    {
        $page['modules'] = $this->get('pages_modules', ['parent' => $page['id'], 'active' => 1], false, 'level', null, ['page_update' => true]);
        $page['modules'] = $this->updateModules($page['modules'], $urlArr, $getArr);
        $page['title'] = $this->ogGet()['title'];

        return $page;
    }

    /*
		Sets path to load module classes
	*/
    public function setPathModules($path)
    {
        $this->path_modules = $path;
    }

    /*
		Updates modules of the page
	*/
    private function updateModules($m, $url, $get)
    {
        if ($m) {
            $modules = new _uho_model_pages_modules($this, $this->path_modules);
            $i = 0;
            foreach ($m as $k => $v) {
                $m[$k] = $modules->updateModule($v, $url, $get);
                if (empty($m[$k])) unset($m[$k]);
            }
        }
        $m = array_values($m);
        return $m;
    }



    /*
		Two function handling global vars for all modules
	*/

    public function setParentVar($key, $value)
    {
        $this->parent_vars[$key] = $value;
    }

    public function getParentVar($key)
    {
        return isset($this->parent_vars[$key]) ? $this->parent_vars[$key] : null;
    }

    /*
        404 page
    */

    public function set404()
    {
        $this->is404 = true;
    }

    public function is404()
    {
        return $this->is404;
    }

    /*
        Returns header data for sharing
    */

    public function ogGet()
    {
        $t = $this->head;

        if (!empty($t['image']) && substr($t['image'], 0, 4) != 'http') {
            $t['image'] = $this->orm->fileRemoveCacheBuster($t['image']);
            $size = _uho_fx::getimagesize($t['image']);
            if ($size) {
                $t['image'] = array(
                    'src' => $t['image'],
                    'width' => $size[0],
                    'height' => $size[1]
                );
            }
        } elseif (!empty($t['image'])) $t['image'] = ['src' => $t['image']];

        if (!empty($t['title']) && $t['title'] == 'Home') $t['title'] = '';
        if (!empty($t['title']) && $t['title']) $t['title'] .= ' - ' . $this->head['app_title'];
        else $t['title'] = $this->head['app_title'];

        return $t;
    }

    /*
        Sets header data for sharing
    */

    public function ogSet($title, $description = '', $image = null)
    {
        if (is_string($image)) $image = $image;
        elseif (is_array($image)) $image = $image[0];
        else $image = null;

        if ($title) $this->head['title'] = strip_tags(str_replace('&nbsp;', ' ', $title));
        if ($description) $this->head['description'] = trim(_uho_fx::headDescription($description, true, 250));
        if ($image) $this->head['image'] = $image;
    }

    public function actionBefore($action, $get)
    {

    }

}
