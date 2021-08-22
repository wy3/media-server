<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/8/9
 * Time: 2:37
 */

namespace MediaServer;


use Evenement\EventEmitter;
use MediaServer\FlvStreamConst as flv;

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


    static public $publishSession = [];

    static public $playerSession = [];

    /**
     * @param $index
     * @return FlvStream|null
     */
    static public function getPublishSession($index)
    {
        return self::$publishSession[$index] ?? null;
    }

    /**
     * @param $index
     * @return bool
     */
    static public function hasPublishSession($index)
    {
        return isset(self::$publishSession[$index]);
    }

    /**
     * @param $index
     * @return PlayerStream[]
     */
    static public function getPlayers($index)
    {
        return self::$playerSession[$index] ?? [];
    }

    static public function deletePlayer($index, $objId)
    {
        unset(self::$playerSession[$index][$objId]);
    }


    /**
     *
     * @param FlvStream $flvStream
     * @param string $pathIndex
     */
    static public function addPublish($flvStream, $pathIndex)
    {
        if(self::hasPublishSession($pathIndex)){
            return false;
        }
        $flvStream->on('flv_ready', function ($flvHeader) use ($pathIndex) {
            //找到所有idling的触发onStart
            foreach (self::getPlayers($pathIndex) as $player) {
                if ($player->idling) {
                    $player->onStartPlay($pathIndex);
                }
            }
        });

        $flvStream->on('flv_tag', function ($tag) use ($pathIndex) {
            //一个flv tag
            foreach (self::getPlayers($pathIndex) as $player) {
                $player->isPlaying && $player->sendTag($tag);
            }
        });


        $flvStream->on('close', function () use ($pathIndex) {
            echo "close",PHP_EOL;
            //结束当前正在播放的player
            foreach (self::getPlayers($pathIndex) as $player) {
                $player->end();
            }
            //清理数据
            unset(
                self::$publishSession[$pathIndex]
            );

        });

        self::$publishSession[$pathIndex] = $flvStream;

        logger()->info(" add publisher {path}", ['path' => $pathIndex]);

        return true;


    }

    /**
     * @param PlayerStream $player
     * @param string $pathIndex
     */
    static public function addPlayer($player, $pathIndex)
    {

        $objIndex = spl_object_id($player);
        $player->on("close", function () use ($pathIndex, $objIndex) {
            self::deletePlayer($pathIndex, $objIndex);
        });

        if (!isset(self::$playerSession[$pathIndex])) {
            self::$playerSession[$pathIndex] = [];
        }

        self::$playerSession[$pathIndex][$objIndex] = $player;

        //判断当前是否有对应的推流设备
        if (self::hasPublishSession($pathIndex)) {
            $player->onStartPlay($pathIndex);
        } else {
            $player->idling = true;
        }

        logger()->info(" add player {path}", ['path' => $pathIndex]);

    }


}
