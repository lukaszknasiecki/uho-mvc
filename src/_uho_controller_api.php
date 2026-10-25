<?php

namespace Huncwot\UhoFramework;

use Huncwot\UhoFramework\_uho_controller;

class _uho_controller_api extends _uho_controller
{

    public $worker_path = null;

    public function getData(): void
    {

        $path = $this->route->e();

        // remove /API/ from path if present
        if ($path[0] === 'api') {
            array_shift($path);
        }

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

        $cfg = $this->cfg;
        $cfg['_path'] = $this->route->e();

        if ($this->worker_path && $action == $this->worker_path) {
            $this->data['content'] = $this->worker();
        } else {
            $this->data['content'] = $this->model->request(
                $method,
                $action,
                $data,
                $cfg
            );
        }
        $this->data['content'] = $this->route->updatePaths($this->data['content']);
        $this->outputType = 'json';
    }

    private function getBody()
    {
        $body = null;
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

    /*
     * Worker method to process queued tasks
     */

    private function worker()
    {

        $loop = true;
        $iMaxWorker = 100;
        $settings = [];
        $worker_max_time = $settings['worker_max_time'] ?? 50;
        $time_start = _uho_fx::microtime_float();
        $time_passed = 0;
        $logs = [];
        $i = 0;

        while ($loop && $iMaxWorker > 0) {
            $r = $this->nextWorker();
            if ($r['result']) $i++;
            if (isset($r['output'])) $logs[] = $r['output'];
            $time_passed = _uho_fx::microtime_float() - $time_start;
            if (!$r['result'] || $time_passed > $worker_max_time) $loop = false;
            $iMaxWorker--;
        }

        return ['result' => true, 'message' => 'Worker finished (' . $i . ')', 'time_passed' => $time_passed, 'logs' => $logs];
    }

    private function nextWorker()
    {
        $worker = $this->model->orm->get(
            [
                'schema' => 'uho_worker',
                'filters' => [
                    'status' => 'waiting'
                ],
                'order' => 'date_created',
                'first' => true
            ]
        );
        if (!$worker) return ['result' => false, 'message' => 'No waiting workers found'];


        $data = explode('?', $worker['action']);
        $action = $data[0];
        if (isset($data[1])) parse_str($data[1], $data);
        else $data = [];

        $result = $this->model->request(
            'GET',
            $action,
            $data,
            $this->cfg
        );

        if ($result['result']) $status = 'success';
        else $status = 'error';

        $this->model->orm->patch(
            'uho_worker',
            ['status' => $status, 'message' => $result['message'] ?? ''],
            ['id' => $worker['id']]
        );

        return [
            'action' => $action,
            'result' => $result['result'],
            'output' => [
                'worker_id' => $worker['id'],
                'action' => $action,
                'output' => $result
            ]
        ];
    }
}
