<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'websocket' => 'app\command\WebSocket',
        'strm:run' => 'app\\command\\StrmTask',
        'strm115:preRefresh' => 'app\\command\\Strm115PreRefresh',
    ],
];
