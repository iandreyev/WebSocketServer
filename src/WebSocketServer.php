<?php

namespace WebSocketServer;

class WebSocketServer
{
    private $address;
    private $port;
    private $socket_master;
    private $clients;
    private $callback_connect;
    private $callback_disconnect;
    private $callback_message;
    private $callback_loop;

    public function __construct($address, $port)
    {
        $this->address = $address;
        $this->port = $port;
        $this->clients = array();
    }

    public function __destruct()
    {
        socket_close($this->socket_master);
    }

    public function run()
    {
        $this->socket_master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->socket_master === false) {
            throw new Exception(socket_strerror(socket_last_error()));
        }
        socket_set_option($this->socket_master, SOL_SOCKET, SO_REUSEADDR, 1);

        if (socket_bind($this->socket_master, $this->address, $this->port) === false) {
            throw new Exception(socket_strerror(socket_last_error()));
        }

        if (socket_listen($this->socket_master, 5) === false) {
            throw new Exception(socket_strerror(socket_last_error()));
        }

        $NULL = null;
        //$this->clients = array($this->socket_master);
        while (true) {
            $sockets_read = array();
            $sockets_read[] = $this->socket_master;
            foreach ($this->clients as $client) {
                $sockets_read[] = $client['socket'];
            }
            socket_select($sockets_read, $NULL, $NULL, 0, 1000000);

            if (in_array($this->socket_master, $sockets_read)) { // new connect
                $socket_new = socket_accept($this->socket_master);
                $this->addClient($socket_new);
                $found_socket = array_search($this->socket_master, $sockets_read);
                unset($sockets_read[$found_socket]);
            }

            foreach ($sockets_read as $socket) {
                $buf = socket_read($socket, 1024 * 10/* , PHP_NORMAL_READ */);
                if (!strlen($buf)) {
                    $this->removeClient($socket);
                } else {
                    $data = $this->decode($buf);
                    $this->messageHandler($socket, $data);
                }
            }

            if (isset($this->callback_loop)) {
                if (!is_callable($this->callback_loop)) {
                    throw new Exception('onLoop is not call back');
                }
                call_user_func($this->callback_loop, $this, $socket);
            }
        }
    }

    private function addClient($socket)
    {
        $header = socket_read($socket, 1024);
        $info = $this->handshaking($header, $socket, $this->address, $this->port);

        $client['socket'] = $socket;
        $client['uri'] = parse_url($info['uri']);
        $client['uri']['queryes'] = array();
        if (isset($client['uri']['query'])) {
            parse_str($client['uri']['query'], $client['uri']['queryes']);
        }

        $this->clients[] = $client;
        if (isset($this->callback_connect)) {
            if (!is_callable($this->callback_connect)) {
                throw new Exception('onConnect is not call back');
            }
            call_user_func($this->callback_connect, $this, $client);
        }
    }

    private function removeClient($socket)
    {
        foreach ($this->clients as $kay => $client) {
            if ($client['socket'] === $socket) {
                if (isset($this->callback_disconnect)) {
                    if (!is_callable($this->callback_disconnect)) {
                        throw new Exception('onDisconnect is not call back');
                    }
                    call_user_func($this->callback_disconnect, $this, $client);
                }

                socket_close($client['socket']);
                unset($this->clients[$kay]);
            }
        }
    }

    private function messageHandler($socket, $data)
    {
        switch ($data['type']) {
            case 'text':
                if (isset($this->callback_message)) {
                    if (!is_callable($this->callback_message)) {
                        throw new Exception('onMessage is not call back');
                    }

                    foreach ($this->clients as $kay => $client) {
                        if ($client['socket'] === $socket) {
                            call_user_func($this->callback_message, $this, $client, $data['payload']);
                        }
                    }
                }
                break;
            case 'close':
                $this->removeClient($socket);
                break;
            case 'ping':
                break;
            case 'pong':
                break;
            default:
                break;
        }
    }

    private function handshaking($header, $client_socket, $host, $port)
    {
        $info = array();
        $headers = array();
        $lines = preg_split("/\r\n/", $header);
        $head = explode(' ', $lines[0]);
        $info['method'] = $head[0];
        $info['uri'] = $head[1];
        foreach ($lines as $line) {
            $line = chop($line);
            if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
                $headers[$matches[1]] = $matches[2];
            }
        }

        $secKey = $headers['Sec-WebSocket-Key'];
        $secAccept = base64_encode(pack('H*', sha1($secKey.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
        $upgrade = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n".
                "Upgrade: websocket\r\n".
                "Connection: Upgrade\r\n".
                "WebSocket-Origin: {$host}\r\n".
                "WebSocket-Location: ws://{$host}:{$port}\r\n".
                "Sec-WebSocket-Accept:$secAccept\r\n\r\n";
        socket_write($client_socket, $upgrade, strlen($upgrade));

        return $info;
    }

    private function encode($payload, $type = 'text', $masked = true)
    {
        $frameHead = array();
        $frame = '';
        $payloadLength = strlen($payload);
        switch ($type) {
            case 'text':
                // first byte indicates FIN, Text-Frame (10000001):
                $frameHead[0] = 129;
                break;
            case 'close':
                // first byte indicates FIN, Close Frame(10001000):
                $frameHead[0] = 136;
                break;
            case 'ping':
                // first byte indicates FIN, Ping frame (10001001):
                $frameHead[0] = 137;
                break;
            case 'pong':
                // first byte indicates FIN, Pong frame (10001010):
                $frameHead[0] = 138;
                break;
        }

        // set mask and payload length (using 1, 3 or 9 bytes)
        if ($payloadLength > 65535) {
            $payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
            $frameHead[1] = ($masked === true) ? 255 : 127;
            for ($i = 0; $i < 8; ++$i) {
                $frameHead[$i + 2] = bindec($payloadLengthBin[$i]);
            }
            // most significant bit MUST be 0 (close connection if frame too big)
            if ($frameHead[2] > 127) {
                $this->close(1004);

                return false;
            }
        } elseif ($payloadLength > 125) {
            $payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
            $frameHead[1] = ($masked === true) ? 254 : 126;
            $frameHead[2] = bindec($payloadLengthBin[0]);
            $frameHead[3] = bindec($payloadLengthBin[1]);
        } else {
            $frameHead[1] = ($masked === true) ? $payloadLength + 128 : $payloadLength;
        }
        // convert frame-head to string:
        foreach (array_keys($frameHead) as $i) {
            $frameHead[$i] = chr($frameHead[$i]);
        }
        if ($masked === true) {
            // generate a random mask:
            $mask = array();
            for ($i = 0; $i < 4; ++$i) {
                $mask[$i] = chr(rand(0, 255));
            }

            $frameHead = array_merge($frameHead, $mask);
        }
        $frame = implode('', $frameHead);
        // append payload to frame:
        $framePayload = array();
        for ($i = 0; $i < $payloadLength; ++$i) {
            $frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
        }

        return $frame;
    }

    private function decode($data)
    {
        $mask = '';
        $unmaskedPayload = '';
        $decodedData = array();
        // estimate frame type:
        $firstByteBinary = sprintf('%08b', ord($data[0]));
        $secondByteBinary = sprintf('%08b', ord($data[1]));
        $opcode = bindec(substr($firstByteBinary, 4, 4));
        $isMasked = ($secondByteBinary[0] == '1') ? true : false;
        $payloadLength = ord($data[1]) & 127;
        if ($isMasked === false) {
            // close connection if unmasked frame is received
            return array('type' => '', 'payload' => '', 'error' => 'protocol error (1002)');
        }
        switch ($opcode) {
            case 1: $decodedData['type'] = 'text';
                break; // text frame
            case 8: $decodedData['type'] = 'close';
                break; // connection close frame
            case 9: $decodedData['type'] = 'ping';
                break; // ping frame
            case 10: $decodedData['type'] = 'pong';
                break; // pong frame
            default:
                // Close connection on unknown opcode
                return array('type' => '', 'payload' => '', 'error' => 'unknown opcode (1003)');
        }
        if ($payloadLength === 126) {
            $mask = substr($data, 4, 4);
            $payloadOffset = 8;
            $dataLength = sprintf('%016b', ord($data[2]).ord($data[3]));
            $dataLength = base_convert($dataLength, 2, 10);
        } elseif ($payloadLength === 127) {
            $mask = substr($data, 10, 4);
            $payloadOffset = 14;
            $dataLength = '';
            for ($i = 2; $i < 8; ++$i) {
                $dataLength .= sprintf('%08b', ord($data[$i]));
            }
            $dataLength = base_convert($dataLength, 2, 10);
        } else {
            $mask = substr($data, 2, 4);
            $payloadOffset = 6;
            $dataLength = base_convert(sprintf('%08b', ord($data[1]) & 63), 2, 10);
        }
        if ($isMasked === true) {
            for ($i = $payloadOffset; $i < $dataLength + $payloadOffset; ++$i) {
                $j = $i - $payloadOffset;
                $unmaskedPayload .= $data[$i] ^ $mask[$j % 4];
            }
            $decodedData['payload'] = $unmaskedPayload;
        } else {
            $payloadOffset = $payloadOffset - 4;
            $decodedData['payload'] = substr($data, $payloadOffset);
        }
        $decodedData['offset'] = $payloadOffset;

        return $decodedData;
    }

    public function sendAll($msg)
    {
        $data = $this->encode($msg, 'text', false);
        foreach ($this->clients as $client) {
            if ($client['socket'] === $this->socket_master) {
                continue;
            }
            @socket_write($client['socket'], $data, strlen($data));
        }
    }

    public function send($client, $msg)
    {
        $data = $this->encode($msg, 'text', false);
        @socket_write($client['socket'], $data, strlen($data));
    }

    public function onConnect($callback)
    {
        $this->callback_connect = $callback;
    }

    public function onDisconnect($callback)
    {
        $this->callback_disconnect = $callback;
    }

    public function onMessage($callback)
    {
        $this->callback_message = $callback;
    }

    public function onLoop($callback)
    {
        $this->callback_loop = $callback;
    }

    public function getClients()
    {
        return $this->clients;
    }
}
