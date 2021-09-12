<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/9/12
 * Time: 23:20
 */

namespace MediaServer\Rtmp;


trait RtmpAuthorizeTrait
{

    public function verifyAuth(){
        return true;
    }

}