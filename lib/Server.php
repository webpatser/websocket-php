<?php

/**
 * Copyright (C) 2014-2020 Textalk/Abicart and contributors.
 *
 * This file is part of Websocket PHP and is free software under the ISC License.
 * License text: https://raw.githubusercontent.com/Textalk/websocket-php/master/COPYING
 */

namespace WebSocket;

class Server extends Base
{
    // Default options
    protected static $default_options = [
      'filter'        => ['text', 'binary'],
      'fragment_size' => 4096,
      'logger'        => null,
      'port'          => 8000,
      'return_obj'    => false,
      'timeout'       => null,
    ];

    protected $addr;
    protected $port;
    protected $listening;
    protected $request;
    protected $request_path;

    /**
     * @param array $options
     *   Associative array containing:
     *   - timeout:       Set the socket timeout in seconds.
     *   - fragment_size: Set framgemnt size.  Default: 4096
     *   - port:          Chose port for listening. Default 8000.
     */
    public function __construct(array $options = [])
    {
        $this->options = array_merge(self::$default_options, $options);
        if (is_null($this->options['timeout'])) {
            $this->options['timeout'] = ini_get('default_socket_timeout');
        }
        $this->port = $this->options['port'];
        $this->setLogger($this->options['logger']);
        $error_msg = '';

        do {
            $socket_name = "tcp://0.0.0.0:{$this->port}";
            $socket = $this->catchError(function () use ($socket_name) {
                $this->logger->debug("Attempt server socket on {$socket_name}");
                return stream_socket_server($socket_name);
            }, function ($socket, $error) use ($socket_name, &$error_msg) {
                $this->logger->warning("Failed server socket on {$socket_name}: {$error->getMessage()}");
                $error_msg = $error->getMessage();
            });
        } while (!$socket && $this->port++ < 10000);

        if (!$socket) {
            $error = "Could not open server socket; {$error_msg}";
            $this->logger->error($error);
            throw new ConnectionException($error, 0);
        }

        $this->listening = $socket;
        $this->logger->info("Server socket on {$socket_name}");
    }

    public function __destruct()
    {
        if ($this->isConnected()) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getPath(): string
    {
        return $this->request_path;
    }

    public function getRequest(): array
    {
        return $this->request;
    }

    public function getHeader($header): ?string
    {
        foreach ($this->request as $row) {
            if (stripos($row, $header) !== false) {
                list($headername, $headervalue) = explode(":", $row);
                return trim($headervalue);
            }
        }
        return null;
    }

    public function accept(): bool
    {
        $this->socket = null;
        return (bool)$this->listening;
    }

    protected function connect(): void
    {
        $this->socket = $this->catchError(function () {
            return stream_socket_accept($this->listening, $this->options['timeout']);
        }, function ($stream, $error) {
            if (!$stream) {
                $this->throwException("Server failed to accept; {$error->getMessage()}");
            }
        });
        if (!$this->socket) {
            $this->throwException('Server failed to accept');
        }
        stream_set_timeout($this->socket, $this->options['timeout']);

        $this->logger->info("Accepted connected to port {port}", [
            'port' => $this->port,
            'pier' => stream_socket_get_name($this->socket, true),
        ]);
        $this->performHandshake();
    }

    protected function performHandshake(): void
    {
        $request = '';
        do {
            $buffer = stream_get_line($this->socket, 1024, "\r\n");
            $request .= $buffer . "\n";
            $metadata = stream_get_meta_data($this->socket);
        } while (!feof($this->socket) && $metadata['unread_bytes'] > 0);

        if (!preg_match('/GET (.*) HTTP\//mUi', $request, $matches)) {
            $error = "No GET in request: {$request}";
            $this->logger->error($error);
            throw new ConnectionException($error);
        }
        $get_uri = trim($matches[1]);
        $uri_parts = parse_url($get_uri);

        $this->request = explode("\n", $request);
        $this->request_path = $uri_parts['path'];
        /// @todo Get query and fragment as well.

        if (!preg_match('#Sec-WebSocket-Key:\s(.*)$#mUi', $request, $matches)) {
            $error = "Client had no Key in upgrade request: {$request}";
            $this->logger->error($error);
            throw new ConnectionException($error);
        }

        $key = trim($matches[1]);

        /// @todo Validate key length and base 64...
        $response_key = base64_encode(pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));

        $header = "HTTP/1.1 101 Switching Protocols\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Accept: $response_key\r\n"
                . "\r\n";

        $this->write($header);
        $this->logger->debug("Handshake on {$get_uri}");
    }
}
