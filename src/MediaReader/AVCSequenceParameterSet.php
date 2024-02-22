<?php


namespace MediaServer\MediaReader;

use MediaServer\Utils\BitReader;

class AVCSequenceParameterSet extends BitReader
{
    public $profile;
    public $level;
    public $width;
    public $height;
    public $avc_ref_frames = 0;

    public function __construct($data)
    {
        parent::__construct($data);
        $this->readData();
    }


    /**
     * read unsigned exp golomb code.
     *
     * @return int
     */
    protected function expGolombUe()
    {
        for ($n = 0; $this->getBit() == 0 && !$this->isError; $n++) ;
        return (1 << $n) + $this->getBits($n) - 1;
    }



    public function getAVCProfileName()
    {
        switch ($this->profile) {
            case 1:
                return 'Main';
            case 2:
                return 'Main 10';
            case 3:
                return 'Main Still Picture';
            case 66:
                return 'Baseline';
            case 77:
                return 'Main';
            case 100:
                return 'High';
            default:
                return '';
        }
    }

    public function readData()
    {
        /*$data = [];
        $data['version'] = ord($this->data[$this->currentBytes++]);
        $data['profile'] = ord($this->data[$this->currentBytes++]);
        $data['profileCompatibility'] = ord($this->data[$this->currentBytes++]);
        $data['level'] = ord($this->data[$this->currentBytes++]);
        $data['naluSize'] = (ord($this->data[$this->currentBytes++]) & 0x03) + 1;
        $data['nbSps'] = ord($this->data[$this->currentBytes++]) & 0x1F;

        $data['sps'] = [];
        for ($i = 0; $i < $data['nbSps']; $i++) {
            //读取sps
            $len = (ord($this->data[$this->currentBytes++]) << 8) | ord($this->data[$this->currentBytes++]);
           var_dump(bin2hex(substr($this->data, $this->currentBytes, $len)));
            //var_dump(base64_encode(substr($this->data, $this->currentBytes, $len)));
            $byteTmp=$this->currentBytes;
            var_dump(bin2hex($this->data[$this->currentBytes]));

            $nalType=ord($this->data[$this->currentBytes++]) & 0x1f;

            if($nalType !== 0x07){
                continue;
            }
            $sps=[];
            $sps['nalType']=$nalType;
            $sps['profileIdc']=ord($this->data[$this->currentBytes++]);
            $sps['flags']=ord($this->data[$this->currentBytes++]);
            $sps['levelIdc']=ord($this->data[$this->currentBytes++]);

            $data['sps'][] = $sps;
            $this->currentBytes = $byteTmp+$len;
        }

        $data['nbPps'] = ord($this->data[$this->currentBytes++]);
        $data['pps'] = [];
        for ($i = 0; $i < $data['nbPps']; $i++) {
            //读取sps
            $len = (ord($this->data[$this->currentBytes++]) << 8) | ord($this->data[$this->currentBytes++]);
            $data['pps'][] = substr($this->data, $this->currentBytes, $len);
            $this->currentBytes += $len;
        }

        var_dump($data);
        return;
        */

        //configurationVersion
        $this->skipBits(8);
        //profile
        $this->profile = $profile = $this->getBits(8);                               // read profile
        //profile compat
        $this->skipBits(8);
        $this->level = $level = $this->getBits(8);                         // level_idc

        $naluSize = ($this->getBits(8) & 0x03) + 1;
        $nb_sps = $this->getBits(8) & 0x1F;


        if ($nb_sps === 0) {
            echo "none sps", PHP_EOL;
            return;
        }

        //nalSize
        $this->getBits(16);

        /* nal type */
        if (($this->getBits(8) & 0x1F) != 0x07) {
            return;
        }

        /* SPS */
        $profile_idc = $this->getBits(8);

        /* flags */
        $this->getBits(8);

        /* level idc */
        $this->getBits(8);


        $this->expGolombUe();                                   // seq_parameter_set_id // sps

        if ($profile_idc == 100 || $profile_idc == 110 ||
            $profile_idc == 122 || $profile_idc == 244 || $profile_idc == 44 ||
            $profile_idc == 83 || $profile_idc == 86 || $profile_idc == 118) {
            /* chroma format idc */
            $cf_idc = $this->expGolombUe();

            if ($cf_idc == 3) {

                /* separate color plane */
                $this->getBits(1);
            }

            /* bit depth luma - 8 */
            $this->expGolombUe();

            /* bit depth chroma - 8 */
            $this->expGolombUe();

            /* qpprime y zero transform bypass */
            $this->getBits(1);

            /* seq scaling matrix present */
            if ($this->getBits(1)) {

                for ($n = 0; $n < ($cf_idc != 3 ? 8 : 12); $n++) {

                    /* seq scaling list present */
                    if ($this->getBits(1)) {

                        /* TODO: scaling_list()
                        if (n < 6) {
                        } else {
                        }
                        */
                    }
                }
            }
        }

        /* log2 max frame num */
        $this->expGolombUe();

        /* pic order cnt type */
        switch ($this->expGolombUe()) {
            case 0:

                /* max pic order cnt */
                $this->expGolombUe();
                break;

            case 1:

                /* delta pic order alwys zero */
                $this->getBits(1);

                /* offset for non-ref pic */
                $this->expGolombUe();

                /* offset for top to bottom field */
                $this->expGolombUe();

                /* num ref frames in pic order */
                $num_ref_frames = $this->expGolombUe();

                for ($n = 0; $n < $num_ref_frames; $n++) {

                    /* offset for ref frame */
                    $this->expGolombUe();
                }
        }


        /* num ref frames */
        $this->avc_ref_frames = $this->expGolombUe();

        /* gaps in frame num allowed */
        $this->getBits(1);

        /* pic width in mbs - 1 */
        $width = $this->expGolombUe();

        /* pic height in map units - 1 */
        $height = $this->expGolombUe();

        /* frame mbs only flag */
        $frame_mbs_only = $this->getBits(1);

        if (!$frame_mbs_only) {

            /* mbs adaprive frame field */
            $this->getBits(1);
        }

        /* direct 8x8 inference flag */
        $this->getBits(1);

        /* frame cropping */
        if ($this->getBits(1)) {

            $crop_left = $this->expGolombUe();
            $crop_right = $this->expGolombUe();
            $crop_top = $this->expGolombUe();
            $crop_bottom = $this->expGolombUe();

        } else {
            $crop_left = 0;
            $crop_right = 0;
            $crop_top = 0;
            $crop_bottom = 0;
        }

        $this->level = $this->level / 10.0;
        $this->width = ($width + 1) * 16 - ($crop_left + $crop_right) * 2;
        $this->height = (2 - $frame_mbs_only) * ($height + 1) * 16 - ($crop_top + $crop_bottom) * 2;

    }


}