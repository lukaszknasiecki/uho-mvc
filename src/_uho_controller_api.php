<?php

namespace Huncwot\UhoFramework;

use Huncwot\UhoFramework\_uho_controller;

class _uho_controller_api extends _uho_controller
{

    public function getData(): void
    {

        $path = $this->route->e();

        // remove /API/ from path
        array_shift($path);

        // create full action path
        $action = implode('/', $path);
        $method = $_SERVER['REQUEST_METHOD'];

        // get input data based on method

        switch ($method) {
            case "GET":
                $data = $this->get;
                break;

            case "DELETE":
            case "PUT":
            case "PATCH":
                $data = $this->get;
                $body = $this->getBody();
                if ($body) $data = array_merge($data, $body);
                break;

            case "POST":

                $data = $this->post;
                $body = $this->getBody();
                if ($body) $data = array_merge($data, $body);
                break;

            default:
                $data = [];
        }

        // launch API method

        $this->data['content'] = $this->model->request(
            $method,
            $action,
            $data,
            $this->cfg
        );
        $this->data['content'] = $this->route->updatePaths($this->data['content']);
        $this->outputType = 'json';
    }

    private function getBody()
    {
        $raw = file_get_contents('php://input');
        if ($raw !== '') {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

            if (str_contains($contentType, 'application/json')) {
                $body = json_decode($raw, true);
            } else {
                parse_str($raw, $body);
            }
        }
        if (is_array($body)) return $body;
    }
}
