<?php


namespace MediaServer\Rtmp;

use MediaServer\MediaReader\AACPacket;
use MediaServer\MediaReader\AudioFrame;


trait RtmpAudioHandlerTrait
{

    public function rtmpAudioHandler()
    {
        //音频包拆解
        /**
         * @var $p RtmpPacket
         */
        $p = $this->currentPacket;
        $audioFrame = new AudioFrame($p->payload, $p->clock);


        if ($this->audioCodec == 0) {
            $this->audioCodec = $audioFrame->soundFormat;
            $this->audioCodecName = $audioFrame->getAudioCodecName();
            $this->audioSamplerate = $audioFrame->getAudioSamplerate();
            $this->audioChannels = ++$audioFrame->soundType;
        }


        if ($audioFrame->soundFormat == AudioFrame::SOUND_FORMAT_AAC) {
            $aacPack = $audioFrame->getAACPacket();
            if ($aacPack->aacPacketType === AACPacket::AAC_PACKET_TYPE_SEQUENCE_HEADER) {
                $this->isAACSequence = true;
                $this->aacSequenceHeaderFrame = $audioFrame;
                $set = $aacPack->getAACSequenceParameterSet();
                $this->audioProfileName = $set->getAACProfileName();
                $this->audioSamplerate = $set->sampleRate;
                $this->audioChannels = $set->channels;
                //logger()->info("publisher {path} recv acc sequence.", ['path' => $this->pathIndex]);
            }

            if ($this->isAACSequence) {
                if ($aacPack->aacPacketType == AACPacket::AAC_PACKET_TYPE_SEQUENCE_HEADER) {

                } else {
                    //音频关键帧缓存
                    $this->gopCacheQueue[] = $audioFrame;
                }
            }


        }


        $this->emit('on_frame', [$audioFrame, $this]);

        //logger()->info("rtmpAudioHandler");

        $audioFrame->destroy();
    }
}
