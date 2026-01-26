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
        $this->data = $this->route->updatePaths($this->data);
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
            'ajax' => $this->route->isAjax(),
            'head' =>
            [
                'og' => $this->model->ogGet(),
                'http_domain' => $this->route->getDomain(),
                'url_now' => rtrim($this->route->getUrlNow(), '/')
            ],
            'view' => 'article'
        ];

        if (!$data['content'] || $this->model->is404()) {
            $data = $this->get404();
            $this->outputType = '404';
        }


        return $data;
    }

    /*
        Actions to be performed before page render
    */
    public function actionBefore($post, $get): void
    {
        $this->post = $post;
        $this->get = $get;
        $this->model->actionBefore($this->route->e(), $get);
    }
}
