<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/8/9
 * Time: 2:37
 */

namespace MediaServer;


use Evenement\EventEmitter;
use Evenement\EventEmitterInterface;
use MediaServer\FlvStreamConst as flv;
use MediaServer\PushServer\PlayStreamInterface;
use MediaServer\PushServer\PublishStreamInterface;
use MediaServer\PushServer\VerifyAuthStreamInterface;
use MediaServer\Rtmp\RtmpStream;

class MediaServer
{

    /**
     * @var EventEmitter
     */
    static protected $eventEmitter;

    /**
     * @return EventEmitter
     */
    static protected function ee()
    {
        if (!self::$eventEmitter) {
            self::$eventEmitter = new EventEmitter();
        }
        return self::$eventEmitter;
    }

    static public function on($event, $listener)
    {
        return self::ee()->on($event, $listener);
    }

    static public function removeListener($event, $listener)
    {
        self::ee()->removeListener($event, $listener);
    }

    static public function removeAllListeners($event = null)
    {
        self::ee()->removeAllListeners($event);
    }

    static public function once($event, $listener)
    {
        return self::ee()->once($event, $listener);
    }


    static public function listeners($event = null)
    {
        return self::ee()->listeners($event);
    }

    static public function emit($event, array $arguments = [])
    {
        self::ee()->emit($event, $arguments);
    }


    /**
     * @var PublishStreamInterface[]
     */
    static public $publishStream = [];

    /**
     * @param $path
     * @return bool
     */
    static public function hasPublishStream($path)
    {
        return isset(self::$publishStream[$path]);
    }

    /**
     * @param $path
     * @return PublishStreamInterface
     */
    static public function getPublishStream($path){
        return self::$publishStream[$path];
    }

    /**
     * @param $stream PublishStreamInterface
     */
    static public function addPublishStream($stream)
    {
        $path = $stream->getPublishPath();
        self::$publishStream[$path] = $stream;
    }

    static public function delPublishStream($path)
    {
        unset(self::$publishStream[$path]);
    }

    /**
     * @var PlayStreamInterface[][]
     */
    static public $playerStream = [];

    /**
     * @param $path
     * @return array|PlayStreamInterface[]
     */
    static public function getPlayStreams($path)
    {
        return self::$playerStream[$path] ?? [];
    }


    /**
     * @param $path
     * @param $objId
     */
    static public function delPlayerStream($path, $objId)
    {
        unset(self::$playerStream[$path][$objId]);
    }

    /**
     * @param $playerStream PlayStreamInterface
     */
    static public function addPlayerStream($playerStream)
    {

        $path = $playerStream->getPlayPath();
        $objIndex = spl_object_id($playerStream);


        if (!isset(self::$playerStream[$path])) {
            self::$playerStream[$path] = [];
        }

        self::$playerStream[$path][$objIndex] = $playerStream;

    }


    /**
     *
     * @param PublishStreamInterface $stream
     * @return mixed
     */
    static public function addPublish($stream)
    {
        $path = $stream->getPublishPath();

        $stream->on('on_publish_ready', function () use ($path) {
            foreach (self::getPlayStreams($path) as $playStream) {
                if ($playStream->isPlayerIdling()) {
                    $playStream->startPlay();
                }
            }
        });


        /**
         * 触发一个包
         */
        $stream->on('on_frame', function ($frame) use ($path) {
            //一个flv tag
            foreach (self::getPlayStreams($path) as $playStream) {
                if (!$playStream->isPlayerIdling()) {
                    $playStream->frameSend($frame);
                }
            }
        });


        $stream->on('on_close', function () use ($path) {
            foreach (self::getPlayStreams($path) as $playStream) {
                $playStream->playClose();
            }

            self::delPublishStream($path);

        });

        self::addPublishStream($stream);

        logger()->info(" add publisher {path}", ['path' => $path]);

        return true;

    }

    /**
     * @param PlayStreamInterface $playerStream
     */
    static public function addPlayer($playerStream)
    {

        $objIndex = spl_object_id($playerStream);
        $path = $playerStream->getPlayPath();

        //on close event
        $playerStream->on("on_close", function () use ($path, $objIndex) {
            self::delPlayerStream($path, $objIndex);
        });

        self::addPlayerStream($playerStream);

        //判断当前是否有对应的推流设备
        if (self::hasPublishStream($path)) {
            $playerStream->startPlay();
        }

        logger()->info(" add player {path}", ['path' => $path]);

    }

    /**
     * @param $stream VerifyAuthStreamInterface
     * @return bool
     */
    static public function verifyAuth($stream)
    {
        return true;
    }


}
