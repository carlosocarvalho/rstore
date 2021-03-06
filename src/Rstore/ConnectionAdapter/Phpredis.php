<?php

namespace Rstore\ConnectionAdapter;

use Redis,
    Rstore\Connection;

class Phpredis implements Connection {

    protected $connection = null;

    public function __construct(Redis $connection) {
        $this->connection = $connection;
    }

    public function rpush($key, $value) {
        return $this->connection->rPush($key, $value);
    }

    public function hset($hash, $key, $value) {
        return $this->connection->hSet($hash, $key, $value);
    }

    public function hget($hash, $key) {
        return $this->connection->hGet($hash, $key);
    }

    public function hgetall($hash) {
        return $this->connection->hGetAll($hash);
    }

    public function hincrby($hash, $key, $amount) {
        return $this->connection->hIncrBy($hash, $key, $amount);
    }

    public function lrange($list, $start, $stop) {
        return $this->connection->lRange($list, $start, $stop);
    }

    public function llen($list) {
        return $this->connection->lLen($list);
    }

    public function zadd($set, $score, $key) {
        return $this->connection->zAdd($set, $score, $key);
    }

    public function zrange($set, $start, $stop) {
        return $this->connection->zRange($set, $start, $stop);
    }

    public function zrevrange($set, $start, $stop) {
        return $this->connection->zRevrange($set, $start, $stop);
    }

    public function flushdb() {
        return $this->connection->flushDB();
    }

    public function select($dbIndex) {
        return $this->connection->select($dbIndex);
    }
}
