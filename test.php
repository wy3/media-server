<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/8/11
 * Time: 1:28
 */
//1G
$begin = microtime(true);
$size = 1024 * 1024 * 512;
$targetSize = $size / 2;
$t = \FFI::new('char[' . $targetSize . ']', false);
\FFI::memset($t, \ord('g'), $targetSize);
$tStr = \FFI::string(\FFI::cast(\FFI::type('char *'), $t), $targetSize);
\FFI::free($t);

$t2 = \FFI::new('char[' . $targetSize . ']', false);
\FFI::memset($t2, \ord('f'), $targetSize);
$tStr2 = \FFI::string(\FFI::cast(\FFI::type('char *'), $t2), $targetSize);
\FFI::free($t2);

$begin = microtime(true);
$n = $tStr . $tStr2;

echo "link string use:" . (microtime(true) - $begin), PHP_EOL;
unset($n);


$nP=pack('@'.(strlen($tStr) + strlen($tStr2)));
$begin = microtime(true);
$nP = substr_replace($nP, $tStr, 0, strlen($tStr));
$nP = substr_replace($nP, $tStr2, strlen($tStr), strlen($tStr2));

echo "replace string use:" . (microtime(true) - $begin), PHP_EOL;
unset($nP);



$c = \FFI::new('char[' . (strlen($tStr) + strlen($tStr2)) . ']', false);
$begin = microtime(true);

//内存拷贝
\FFI::memcpy($c, $tStr, strlen($tStr));
\FFI::memcpy($c + strlen($tStr), $tStr2, strlen($tStr2));
echo "memcpy string use:" . (microtime(true) - $begin), PHP_EOL;
\FFI::free($c);
//sleep(10);


exit;


//echo "create string use:" . (microtime(true) - $begin), PHP_EOL;
$times = 1000;
$begin = microtime(true);
for ($j = 0; $j < $times; $j++) {

    $c = \FFI::new('char[' . $size . ']', false);
    //内存拷贝
    \FFI::memcpy($c + 1, $tStr, $targetSize);

    //var_dump([$c[0],$c[1]]);

    \FFI::free($c);

}
echo "ffi_replace_use:\t" . ((microtime(true) - $begin) / $times), PHP_EOL;

$begin = microtime(true);
for ($j = 0; $j < $times; $j++) {
    $mb = pack('@1') . $tStr . pack('@' . ($size / 2 - 1));;
    //var_dump([$mb[0],$mb[1]]);
    unset($mb);
}
echo "._concat_use:\t\t" . ((microtime(true) - $begin) / $times), PHP_EOL;

$begin = microtime(true);
for ($j = 0; $j < $times; $j++) {
    $mb = pack('@' . ($size));
    $mb = substr_replace($mb, $tStr, 1, $targetSize);
    //var_dump([$mb[0],$mb[1]]);
    unset($mb);
}
echo "sub_replace_use:\t" . ((microtime(true) - $begin) / $times), PHP_EOL;

//sleep(10);
unset($tStr);
exit;

/*

list(,$a)=unpack("N", "\x00\x00\x00\x03");
var_dump($a);

    exit;

require_once __DIR__ . '/src/SabreAMF/OutputStream.php';
require_once __DIR__ . '/src/SabreAMF/InputStream.php';

require_once __DIR__ . '/src/SabreAMF/AMF0/Serializer.php';
require_once __DIR__ . '/src/SabreAMF/AMF0/Deserializer.php';

use \SabreAMF_AMF0_Deserializer;
use \SabreAMF_InputStream;


$test=hex2bin("020007636f6e6e656374003ff00000000000000300036170700200046c69766500047479706502000a6e6f6e707269766174650008666c617368566572020024464d4c452f332e302028636f6d70617469626c653b204c61766635382e32372e313033290005746355726c02001a72746d703a2f2f3132372e302e302e313a31");
$stream = new SabreAMF_InputStream($test);
$deserializer = new SabreAMF_AMF0_Deserializer($stream);

var_dump($deserializer->readAMFData());

exit;


switch (1){
    case 0:
        echo 0;
    case 1:
        echo 1;

    case 2:
        echo 2;
    case 3:
        echo 3;
}
exit;
$size = 102460000;
echo bin2hex(pack("N", $size)), PHP_EOL;

echo bin2hex(pack("a3C", pack("N", $size << 8), $size >> 24));
exit;

$p = pack("@10");
echo bin2hex($p), PHP_EOL;
$p[1] = 15;
echo bin2hex($p), PHP_EOL;

echo bin2hex(chr(256));
*/
