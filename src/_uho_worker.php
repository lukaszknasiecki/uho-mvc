<?php

namespace Huncwot\UhoFramework;

use Huncwot\UhoFramework\_uho_fx;

/**
 * This is a worker class for managing asynchronious executing or heavy tasks
 * Works with schema defined in /schemas/uho_worker.json
 * 
 */

class _uho_worker
{
    /**
     * Indicates timestamp set once
     * this class is started
     */
    private float $start = 0;
    private $schema_name = 'uho_worker';

    /*
        * Instance of _uho_orm class
        */
    private _uho_orm $orm;

    /**
     * Constructor
     * @param object $orm instance of _uho_orm class
     * @return null
     */

    public function __construct(_uho_orm $orm)
    {
        $this->orm = $orm;
        $this->start = _uho_fx::microtime_float();
    }

    /**
     * Loads first waiting worker item
     * @return array
     */

    public function get()
    {
        return $this->orm->get($this->schema_name, ['status' => 'waiting'], true, 'date_created,id');
    }

    /**
     * Return number of waiting items
     * @return int
     */
    public function getCountWaiting()
    {
        return $this->orm->get($this->schema_name, ['status' => 'waiting'], false, null, null, ['count' => true]);
    }

    /**
     * Return number of items completed today
     * @return int
     */

    public function getCountToday()
    {
        return $this->orm->get($this->schema_name, ['status' => 'success', 'date_completed' => ['operator' => '>=', 'value' => date('Y-m-d')]], false, null, null, ['count' => true]);
    }

    /**
     * Returns current time from this instance start
     * @return int
     */

    public function getTime()
    {
        return (_uho_fx::microtime_float() - $this->start);
    }

    /**
     * Checks if given time passed from this instance start
     * @return boolean
     */

    public function checkTime($seconds)
    {
        $time = (_uho_fx::microtime_float() - $this->start);
        return ($time < $seconds);
    }

    /**
     * Sets new status for worker item
     * @param integer $id
     * @param string $status
     * @return boolean
     */

    public function setStatus($id, $status)
    {
        return $this->orm->put($this->schema_name, ['status' => $status, 'date_completed' => date('Y-m-d H:i:s')], ['id' => $id]);
    }

    /**
     * Adds new items to worker
     *
     * @param array $actions
     */
    public function add($actions): void
    {
        if (!is_array($actions)) $actions = [$actions];
        foreach ($actions as $v)
            $this->orm->post($this->schema_name, ['action' => $v, 'status' => 'waiting']);
    }

    public function patch($actions): void
    {
        if (!is_array($actions)) $actions = [$actions];
        foreach ($actions as $v)
            $this->orm->patch($this->schema_name, ['action' => $v, 'status' => 'waiting'],['action' => $v, 'status' => 'waiting']);
    }

    /**
     * Adds existing item again to the query
     *
     * @param integer $id
     */
    public function addRepeat($id): void
    {
        $data = $this->orm->get($this->schema_name, ['id' => $id], true);
        if ($data) {
            $data = ['action' => $data['action'], 'status' => 'waiting'];
            $this->orm->post($this->schema_name, $data);
        }
    }
}
