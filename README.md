# WebSocketServer

[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)

**WebSocketServer** - A WebSocket server class.

## Install

Via Composer

``` bash
$ composer require dimrakitin/websocketserver
```

## Usage

*server.php*

``` php
define('ADDRESS', '127.0.0.1');
define('PORT', 5555);

$server = new WebSocketServer\WebSocketServer(ADDRESS, PORT);
echo 'Server started '.ADDRESS.':'.PORT."\n";
```
** Add callback function**

``` php
$server->onConnect = function ($server, $client) {
    socket_getpeername($client['socket'], $ip);
    echo 'New connect '.$ip."\n";
};

$server->onDisconnect = function ($server, $client) {
    socket_getpeername($client['socket'], $ip);
    echo 'Disconnect '.$ip."\n";
};

$server->onMessage = function ($server, $client, $data) {
    socket_getpeername($client['socket'], $ip);
    echo 'onMessage '.$ip.' - '.$data."\n";
    $server->sendAll($data);
};
```

** Run loop **

``` php
$server->run();
```

** Client code **

``` javascript
var wsUri = "ws://localhost:5555";
websocket = new WebSocket(wsUri);

websocket.onopen = function(ev) {};

websocket.onmessage = function(ev) {
    var data = ev.data;
    console.log(data);
};

websocket.onclose = function(ev) {
    console.log("onclose");
};

websocket.onerror = function(ev) {};

window.onbeforeunload = function() {
    websocket.close();
    websocket = null;
};
```


Full example you can see at [github]()


## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
