# PHP-Media-Server

## 说明

> 本项目为基于workerman实现的纯PHP流媒体服务

目前实现的功能:
    
    rtmp(flv)推流

    rtmp,httpflv,wsflv播放


需要注意的是目前功能并未完善, 可能会遇到但不仅限于以下问题:

    1. 视频流加载缓慢
    
    2. 部分播放器可能播放有问题
    
    3. 不支持多进程
    
    4. 非 h264/aac 流支持可能存在问题
    
    5. 可能存在内存溢出，特定环境下可能会报错退出

    ...

> 另外Node-Media-Server, sabreamf 等开源项目为本项目中协议的解析实现提供了极大的帮助

## 安装

```php 7.4```

```composer install```

## 启动

````
php start.php start
````             

## ffmpeg推流

```
ffmpeg.exe -re -stream_loop 1  -i "file.mp4" -vcodec h264 -acodec aac -f flv rtmp://127.0.0.1/a/b
```

## 播放

rtmp播放地址: ``rtmp://127.0.0.1/a/b``

httpflv播放地址: ``http://127.0.0.1:18080/a/b.flv``

wsflv播放地址: ``ws://127.0.0.1:18080/a/b.flv``

web播放sdk: [Aliplayer](http://player.alicdn.com/aliplayer/setting/setting.html)

客户端播放器: ``vlc``,``ffplay``



## 致谢

https://www.workerman.net

https://github.com/illuspas/Node-Media-Server

https://code.google.com/archive/p/sabreamf/
   
