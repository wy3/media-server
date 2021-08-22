<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/8/10
 * Time: 1:15
 */

namespace MediaServer;


use Evenement\EventEmitter;

class Publisher extends EventEmitter
{

    public $key;

    public function __construct($key)
    {
        $this->key = $key;
    }


}