<?php

namespace Huncwot\UhoFramework;

use Huncwot\UhoFramework\_uho_controller;

class _uho_controller_pages extends _uho_controller
{

    /*
        gets full page data, updates URls
    */
    public function getData(): void
    {

        $this->data = $this->getContentData();
        $this->data['ajax'] = $this->route->isAjax();
        $this->data['head'] = $this->model->ogGet();
        $this->data = $this->route->updatePaths($this->data);
        if ($this->model->is404()) $this->outputType = '404';
    }
    
    /*
        gets data for <article> secition, built from modules
    */
    public function getContentData(): array
    {
        $data = [
            'content' => $this->model->getContentData([
                'url' => $this->route->getPathNow(),
                'get' => $this->get
            ]),
            'view' => 'article'
        ];

        if (!$data['content']) {
            $data = $this->get404();
        }

        return $data;
    }

    /*
        Actions to be performed before page render
    */
    public function actionBefore($post, $get) : void
    {
        $this->post = $post;
        $this->get = $get;
        $this->model->actionBefore($this->route->e(), $get);
    }

}