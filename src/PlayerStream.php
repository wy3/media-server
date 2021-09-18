<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/8/12
 * Time: 23:37
 */

namespace MediaServer;


use Evenement\EventEmitter;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;
use MediaServer\FlvStreamConst as flv;

class PlayerStream extends EventEmitter
{
    /**
     * @var EventEmitter|WritableStreamInterface
     */
    private $input;
    private $closed = false;
    private $ended = false;

    private $buffer = "";


    public $flvHeader;
    public $hasFlvHeader = false;

    public $isACCSequence = false;
    public $accSequence;

    public $isAVCSequence = false;
    public $avcSequence;

    public $isMetaData = false;
    public $metaData;

    public $isReady = false;

    public $pathIndex = "";

    public function __destruct()
    {
        logger()->info("player stream {path} destruct", ['path' => $this->pathIndex]);
    }

    /**
     * PlayerStream constructor.
     * @param $input EventEmitter|ReadableStreamInterface
     * @param $pathIndex string
     */
    public function __construct($input, string $pathIndex)
    {
        $this->input = $input;
        $this->pathIndex = $pathIndex;
        $input->on('error', [$this, 'streamError']);
        $input->on('end', [$this, 'end']);
        $input->on('close', [$this, 'close']);
    }

    public function write($data)
    {
        return $this->input->write($data);
    }

    public function isWritable()
    {
        return $this->input->isWritable();
    }

    public function end($data = null)
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        $this->input->end($data);
        $this->emit('end');
        $this->close();
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->buffer = "";
        $this->input->close();
        $this->emit('close');
        $this->removeAllListeners();
    }


    public $idling = false;

    public $isPlaying = false;

    public $hasFirstVideoFrame = false;

    public $enableGop = true;

    public $enableAudio = true;

    public $enableVideo = true;


    /** @internal */
    public function streamError(\Exception $e)
    {
        $this->emit('error', [$e]);
        $this->close();
    }


    /**
     * @param $index
     */
    public function onStartPlay($index)
    {

        $flvStream = MediaServer::getPublishSession($index);
        if ($flvStream->hasFlvHeader) {
            $flvHeader = "FLV\x01\x00" . pack('NN', 9, 0);
            if ($this->enableAudio && $flvStream->hasAudio) {
                $flvHeader[4] = \chr(\ord($flvHeader[4]) | 4);
            }
            if ($this->enableVideo && $flvStream->hasVideo) {
                $flvHeader[4] = \chr(\ord($flvHeader[4]) | 1);
            }
            $this->write($flvHeader);
        }

        if ($flvStream->isMetaData) {
            $this->write(flv::createFlvTag($flvStream->metaData));
        }

        if ($this->enableAudio && $flvStream->isACCSequence) {
            $this->write(flv::createFlvTag($flvStream->accSequence));
        }

        if ($this->enableVideo && $flvStream->isAVCSequence) {
            $this->write(flv::createFlvTag($flvStream->avcSequence));
        }

        if ($this->enableGop) {
            foreach ($flvStream->gopCacheQueue as &$tag) {
                $this->sendTag($tag);
            }
        }


        $this->idling = false;
        $this->isPlaying = true;

        logger()->info(" player {path} on start play", ['path' => $this->pathIndex]);
    }

    public function sendTag(&$tag)
    {
        switch ($tag['type']) {
            case flv::SCRIPT_TAG:
                $this->write(flv::createFlvTag($tag));
                break;
            case flv::VIDEO_TAG:
                if (!$this->enableVideo) {
                    break;
                }
                //视频数据
                if ($tag['frameType'] == flv::VIDEO_FRAME_TYPE_KEY_FRAME) {
                    $this->hasFirstVideoFrame = true;
                }
                if ($this->hasFirstVideoFrame) {
                    $this->write(flv::createFlvTag($tag));
                }
                break;
            case flv::AUDIO_TAG:
                if (!$this->enableAudio) {
                    break;
                }
                //音频数据
                $this->write(flv::createFlvTag($tag));
                break;
        }
    }

}
