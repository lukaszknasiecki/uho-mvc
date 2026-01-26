<?php

namespace Huncwot\UhoFramework;

/**
 * Class used by _uho_model_pages
 * to handle page modules
 */

class _uho_model_pages_modules
{

	public $parent=null;
	
	private $lang, $lang_add;
	private $models_path = '';
	private $debug = false;
	private $debug_iModule = 0;

	function __construct($m, $path)
	{
		$this->models_path = $path;
		$this->parent = $m;
		$this->lang_add = $m->lang_add;
		$this->lang = $m->lang;
	}

	/*
		Updates single module including using it's class if exists
	*/

	public function updateModule($m, $url, $get)
	{

		$enter = chr(13) . chr(10);

		/*
			Debug vars
		*/


		if ($this->debug && !empty($get['dbg'])) {
			$this->debug_iModule++;
			echo ($enter . '<!-- ' . $this->debug_iModule . '. MODULE ' . $m['type']['slug'] . ' -->' . $enter);
		}

		$self = $m['type']['slug'];

		$settings = [
			'url' => $url,
			'get' => $get
		];


		/*
			Sets common variables
		*/

		$m['preview'] = isset($get['preview']);
		$m['lang'] = $this->lang;
		$m['links'] =
			[
				'url_home' => ''
			];

		/*
			Uses's page module class
			for additional updates
		*/

		if (file_exists($this->models_path . 'm_' . $self . '.php')) {
			require_once $this->models_path . 'm_' . $self . '.php';
			$module_model = 'model_app_pages_modules_' . $self;
			$class = new $module_model($this->parent, $settings);
			$m = $class->updateModel($m, $url);
		}

		return $m;
	}
}
