<?php

namespace Huncwot\UhoFramework;

/**
 * This class a simple worker class for asynchronious
 * executing or heavy tasks
 */


class _uho_worker
{
    /**
     * Indicates timestamp set once
     * this class is started
     */
    private $start = null;
    private $orm;

    /**
     * Constructor
     * @param object $orm instance of _uho_orm class
     * @return null
     */

    function __construct($orm)
    {
        $this->orm = $orm;
        $this->start = $this->microtime_float();
    }

    /**
     * Urility function
     * @return float
     */

    function microtime_float()
    {
        list($usec, $sec) = explode(" ", microtime());
        return ((float)$usec + (float)$sec);
    }

    /**
     * Loads first worker item
     * @return array
     */

    function get()
    {
        return $this->orm->getJsonModel('uho_worker', ['status' => 'waiting'], true, 'date_created,id');
    }

    /**
     * Return number of waiting items
     * @return int
     */
    public function getCountWaiting()
    {
        return $this->orm->getJsonModel('uho_worker', ['status' => 'waiting'], false, null, null, ['count' => true]);
    }

    /**
     * Return number of items completed today
     * @return int
     */
    public function getCountToday()
    {
        return $this->orm->getJsonModel('uho_worker', ['status' => 'success', 'date_completed' => ['operator' => '>=', 'value' => date('Y-m-d')]], false, null, null, ['count' => true]);
    }

    /**
     * Returns current time from this instance start
     * @return int
     */

    public function getTime()
    {
        return ($this->microtime_float() - $this->start);
    }

    /**
     * Checks if given time passed from this instance start
     * @return boolean
     */

    public function checkTime($seconds)
    {
        $time = ($this->microtime_float() - $this->start);
        return ($time < $seconds);
    }

    /**
     * Sets new status for workeritem
     * @param integer $id
     * @param string $status
     * @return boolean
     */

    function setStatus($id, $status)
    {
        return $this->orm->putJsonModel('uho_worker', ['status' => $status, 'date_completed' => date('Y-m-d H:i"s')], ['id' => $id]);
    }

    /**
     * Adds new items to worker
     *
     * @param array $actions
     */
    function add($actions): void
    {
        if (!is_array($actions)) $actions = [$actions];
        foreach ($actions as $v)
            $this->orm->postJsonModel('uho_worker', ['action' => $v, 'status' => 'waiting']);
    }

    /**
     * Adds existing item again to the query
     *
     * @param integer $id
     */
    function addRepeat($id): void
    {
        $data = $this->orm->getJsonModel('uho_worker', ['id' => $id], true);
        if ($data) {
            $data = ['action' => $data['action'], 'status' => 'waiting'];
            $this->orm->postJsonModel('uho_worker', $data);
        }
    }
}
