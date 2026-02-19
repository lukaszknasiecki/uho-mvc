<?php

if (!function_exists('dd')) {
    /**
     * Dump the passed variables and end the script.
     *
     * @param  mixed  $val
     * @return void
     */
    function dd($val)
    {
        var_dump($val);
        die();
    }
}
