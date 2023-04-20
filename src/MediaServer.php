<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/8/9
 * Time: 2:37
 */

namespace MediaServer;


use Channel\Server;
use Evenement\EventEmitter;
use Evenement\EventEmitterInterface;
use MediaServer\FlvStreamConst as flv;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\PushServer\PlayStreamInterface;
use MediaServer\PushServer\PublishStreamInterface;
use MediaServer\PushServer\VerifyAuthStreamInterface;
use MediaServer\Rtmp\RtmpStream;
use Workerman\Timer;
use Workerman\Worker;

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

    static function serverCenterInit()
    {
        $channelServer = new Server("unix://__DIR__/center");
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
    static public function getPublishStream($path)
    {
        return self::$publishStream[$path];
    }

    /**
     * @param $stream PublishStreamInterface
     */
    static protected function addPublishStream($stream)
    {
        $path = $stream->getPublishPath();
        self::$publishStream[$path] = $stream;
    }

    static protected function delPublishStream($path)
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
    static protected function delPlayerStream($path, $objId)
    {
        unset(self::$playerStream[$path][$objId]);
        //一个播放设备都没有
        if (self::hasPublishStream($path) && count(self::getPlayStreams($path)) == 0) {
            $p_stream = self::getPublishStream($path);
            $p_stream->removeListener('on_frame', self::class . '::publisherOnFrame');
            $p_stream->is_on_frame = false;
        }
    }

    /**
     * @param $playerStream PlayStreamInterface
     */
    static protected function addPlayerStream($playerStream)
    {

        $path = $playerStream->getPlayPath();
        $objIndex = spl_object_id($playerStream);


        if (!isset(self::$playerStream[$path])) {
            self::$playerStream[$path] = [];
        }

        self::$playerStream[$path][$objIndex] = $playerStream;

        if (self::hasPublishStream($path)) {
            $p_stream = self::getPublishStream($path);
            if (!$p_stream->is_on_frame) {
                $p_stream->on('on_frame', self::class . '::publisherOnFrame');
                $p_stream->is_on_frame = true;
            }
        }

    }


    /**
     * @param $publisher PublishStreamInterface
     * @param $frame MediaFrame
     */
    static function publisherOnFrame($frame, $publisher)
    {
        foreach (self::getPlayStreams($publisher->getPublishPath()) as $playStream) {
            if (!$playStream->isPlayerIdling()) {
                $playStream->frameSend($frame);
            }
        }
    }


    /**
     *
     * @param PublishStreamInterface $stream
     * @return mixed
     */
    static public function addPublish($stream)
    {
        $path = $stream->getPublishPath();
        $stream->is_on_frame = false;

        $stream->on('on_publish_ready', function () use ($path) {
            foreach (self::getPlayStreams($path) as $playStream) {
                if ($playStream->isPlayerIdling()) {
                    $playStream->startPlay();
                }
            }
        });

        if (count(self::getPlayStreams($path)) > 0) {
            $stream->on('on_frame', self::class . '::publisherOnFrame');
            $stream->is_on_frame = true;
        }


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
            //echo "play on close", PHP_EOL;
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
