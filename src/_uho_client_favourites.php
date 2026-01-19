<?php

namespace Huncwot\UhoFramework;

/**
 * This class provides favourites functionality for CLIENT object
 * Handles loading, checking, and toggling user favourites
 */

class _uho_client_favourites
{
  /**
   * _uho_orm instance
   */
  private $orm;
  /**
   * Favourites settings
   */
  private $settings;
  /**
   * Session key for favourites
   */
  private $sessionKey = 'fav';
  /**
   * Callback to get current user id
   */
  private $getUserIdCallback;

  /**
   * Class constructor
   * @param _uho_orm $orm _uho_orm class instance
   * @param array $settings favourites settings ['model' => 'client_favourites']
   * @param callable $getUserIdCallback callback function to get current user id
   */
  function __construct($orm, $settings, callable $getUserIdCallback)
  {
    $this->orm = $orm;
    $this->settings = $settings;
    $this->getUserIdCallback = $getUserIdCallback;
  }

  /**
   * Returns current user id using the callback
   * @return int|null
   */
  private function getUserId()
  {
    return call_user_func($this->getUserIdCallback);
  }

  /**
   * Checks if user is logged in
   * @return bool
   */
  private function isLogged(): bool
  {
    return !empty($this->getUserId());
  }

  /**
   * Loads favourites data from DB to Session VAR
   */
  public function load(): void
  {
    if ($this->settings && $this->isLogged()) {
      $t = $this->orm->get($this->settings['model'], ['user' => $this->getUserId()], false);
      $types = [];
      foreach ($t as $v) {
        if (!isset($types[$v['type']])) $types[$v['type']] = [];
        $types[$v['type']][$v['object_id']] = 1;
      }
      $_SESSION[$this->sessionKey] = $types;
    }
  }

  /**
   * Returns favourites data by type
   *
   * @param string $type data type
   *
   * @return array|null
   */
  public function get($type)
  {
    if ($this->isLogged()) {
      $items = @$_SESSION[$this->sessionKey][$type];
      $i = [];
      if ($items)
        foreach ($items as $k => $_)
          $i[] = $k;
      return $i;
    }
  }

  /**
   * Checks if object is in favourites
   * @param string $type data type
   * @param int $id objects' id
   * @return mixed
   */
  public function check($type, $id)
  {
    if ($this->isLogged()) return @$_SESSION[$this->sessionKey][$type][$id];
  }

  /**
   * Toggles objects in favourites
   * @param string $type data type
   * @param int $id objects' id
   * @return array
   */
  public function toggle($type, $id): array
  {
    if (!isset($type) || !isset($id) || !$this->isLogged()) return ['result' => false];

    if (isset($_SESSION[$this->sessionKey][$type][$id])) {
      unset($_SESSION[$this->sessionKey][$type][$id]);
      if ($this->settings['model']) $this->orm->delete($this->settings['model'], ['user' => $this->getUserId(), 'type' => $type, 'object_id' => $id]);
    } else {
      if (!isset($_SESSION[$this->sessionKey][$type])) $_SESSION[$this->sessionKey][$type] = [];
      $_SESSION[$this->sessionKey][$type][$id] = 1;
      if ($this->settings['model']) $this->orm->post($this->settings['model'], ['user' => $this->getUserId(), 'type' => $type, 'object_id' => $id]);
    }
    return ['result' => true, 'status' => intval(@$_SESSION[$this->sessionKey][$type][$id])];
  }

  /**
   * Clears all favourites from session
   */
  public function clear(): void
  {
    unset($_SESSION[$this->sessionKey]);
  }
}
