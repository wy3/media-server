<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/8/23
 * Time: 0:22
 */

namespace MediaServer\Rtmp;


use MediaServer\MediaReader\AAC;
use MediaServer\MediaReader\AACSequenceParameterSet;
use MediaServer\MediaReader\AudioAnalysis;
use MediaServer\MediaReader\AudioFrame;
use React\EventLoop\Loop;
use \Exception;

trait RtmpAudioHandlerTrait
{

    public function rtmpAudioHandler()
    {
        //音频包拆解
        $p = $this->currentPacket;
        var_dump(bin2hex($p->payload));
        $audioFrame = AudioAnalysis::audioFrameDataRead($p->payload);

        if ($this->audioCodec == 0) {
            $this->audioCodec = $audioFrame->soundFormat;
            $this->audioCodecName = $audioFrame->getAudioCodecName();
            $this->audioSamplerate = $audioFrame->getAudioSamplerate();
            $this->audioChannels = ++$audioFrame->soundType;
        }


        if ($audioFrame->soundFormat == AudioAnalysis::SOUND_FORMAT_AAC) {
            $accPack = AAC::packetRead($audioFrame->data);
            var_dump(bin2hex($accPack->data));
            if ($accPack->aacPacketType === AAC::AAC_PACKET_TYPE_SEQUENCE_HEADER) {
                $this->isAACSequence = true;
                $this->aacSequenceHeader = $p;
                $set = new AACSequenceParameterSet($accPack->data);
                $set->readData();
                $this->audioProfileName = AAC::getAACProfileName($set);
                $this->audioSamplerate = $set->sampleRate;
                $this->audioChannels = $set->channels;

                var_dump([$this->audioProfileName,$this->audioSamplerate,$this->audioChannels,$set]);
                //logger()->info("publisher {path} recv acc sequence.", ['path' => $this->pathIndex]);
            }

            if ($accPack->aacPacketType == AAC::AAC_PACKET_TYPE_SEQUENCE_HEADER) {

            } else {
                //音频关键帧缓存
                $this->gopCacheQueue[] = &$tag;
            }
        }

        exit;
        logger()->info("rtmpAudioHandler");
    }
}