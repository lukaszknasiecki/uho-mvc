<?php

namespace Huncwot\UhoFramework;

/**
 * This is the main controller class
 */

class _uho_controller
{
    /**
     * current html output
     */
    public $html;
    /**
     * curent data loaded via model instance
     */
    public $data;
    /**
     * instance of _uho_model
     */
    public $model;
    /**
     * instancw of _uho_view
     */
    public $view;
    /**
     * config derived from _uho_application
     */
    public $cfg;
    /**
     * Default output type
     */
    public $outputType = 'html';
    /**
     * Current $_GET array
     */
    public $get;
    /**
     * Current $_POST array
     */
    public $post;
    /**
     * Indicates if mySQL mode is enabled
     */
    public $no_sql;

    public object $route;


    /**
     * Class constructor
     * @param array $cfg config array
     * @param object $model _uho_model instance
     * @param object $view _uho_view instance
     * @param object $router _uho_route instance
     * @return null
     */

    public function __construct(array $cfg, object $model, object $view, object $router)
    {
        $this->cfg = $cfg;
        $this->model = $model;
        if (isset($cfg['s3'])) $this->model->setS3($cfg['s3']);
        if (isset($this->model)) $this->model->csrf_token_create(@$this->application_params['application_domain']);
        $this->view = $view;
        $this->route = $router;
    }

    /**
     * Controller first call from the App
     *
     * @param array $post POST data
     * @param object $get GET data
     */
    public function actionBefore($post, $get): void
    {
        $this->post = $post;
        $this->get = $get;
        if (isset($get['setlang'])) {
            $this->route->setCookieLang($this->model->lang);
        }
    }


    /**
     * Gets data, to be overwritten by child instances
     */
    public function getData(): void {}

    /**
     * Gets APP data and sets current lang
     */
    public function getAppData(): void
    {
        $this->getData();
        $this->data['lang'] = $this->model->lang;
    }


    /**
     * Sets ouput for 404 page
     * @param string $url 404 page url
     * @return array
     */

    public function get404($url = '404')
    {
        $data['content'] = $this->model->get404($url);
        $data['view'] = '404';
        $this->outputType = '404';
        return $data;
    }

    /**
     * Calls views by type
     *
     * @param string $type output type
     */
    public function getOutput($type): string
    {
        switch ($type) {
            case 'html':
            case '404':
                return ($this->getOutputHtml());

            case 'json':
                return ($this->getOutputJson());

            case 'json_raw':
                return ($this->getOutputJsonRaw());

            case 'rss':
                return ($this->getOutputRss());

            default:
                exit('UHO_CONTROLLER:: Invalid output type: ' . $type);
        }
    }

    /**
     * Gets HTML output from View
     * @return string
     */

    public function getOutputHtml()
    {
        $html = $this->view->getHtml($this->data);
        return $html;
    }

    /**
     * Gets JSON output from View
     * @return string
     */

    public function getOutputJson()
    {
        return (json_encode($this->data['content'], JSON_PRETTY_PRINT));
    }

    /**
     * Gets JSON output from View
     * @return string
     */

    public function getOutputJsonRaw()
    {
        return (json_encode($this->data['content']));
    }

    /**
     * Gets RSS output from View
     * @return string
     */

    public function getOutputRss()
    {
        return ($this->data['content']['rss']);
    }

    /**
     * Sets noSQL mode
     */
    public function setNoSql(): void
    {
        $this->no_sql = true;
    }
}
