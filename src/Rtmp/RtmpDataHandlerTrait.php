<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/8/23
 * Time: 0:22
 */

namespace MediaServer\Rtmp;


use React\EventLoop\Loop;
use \Exception;

trait RtmpDataHandlerTrait
{

    /**
     * @throws Exception
     */
    public function rtmpDataHandler()
    {
        $p = $this->currentPacket;
        //AMF0 数据解释
        $dataMessage = RtmpAMF::rtmpDataAmf0Reader($p->payload);
        logger()->info("rtmpDataHandler {$dataMessage['cmd']} " . json_encode($dataMessage));
    }
}