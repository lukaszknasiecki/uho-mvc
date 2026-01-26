<?php

namespace Huncwot\UhoFramework;

use Huncwot\UhoFramework\_uho_controller;

class _uho_controller_api extends _uho_controller
{

    public function getData(): void
    {

        $path = $this->route->e();

        // remove API from path
        array_shift($path);

        // create full action path
        $action = implode('/', $path);
        $method = $_SERVER['REQUEST_METHOD'];

        // get input data based on method

        switch ($method) {
            case "GET":
                $data = $this->get;
                $json = file_get_contents('php://input');
                if (is_string($json)) $json = json_decode($json, true);
                if ($json) $data = array_merge($data, $json);

                break;

            case "POST":

                $data = $this->post;
                if (empty($data)) $data = $this->get;
                $json = file_get_contents('php://input');
                if (is_string($json)) $json = json_decode($json, true);
                if ($json) $data = array_merge($data, $json);
                break;

            case "PUT":
            case "PATCH":
            case "DELETE":

                $data = file_get_contents('php://input');
                if (is_string($data)) $data = json_decode($data, true);
                if (empty($data)) $data = $this->get;
                break;
            default:
                $data = [];
        }

        // launch API method

        $this->data['content'] = $this->model->request(
            $method,
            $action,
            $data,
            $path,
            $this->cfg
        );
        $this->data['content'] = $this->route->updatePaths($this->data['content']);
        $this->outputType = 'json';
    }
}
