<?php
namespace Aws;

use Psr\SimpleCache\CacheInterface as SimpleCacheInterface;

class Psr16CacheAdapter implements CacheInterface
{
    public function __construct(private readonly SimpleCacheInterface $cache)
    {
    }

    public function get($key)
    {
        return $this->cache->get($key);
    }

    public function set($key, $value, $ttl = 0): void
    {
        $this->cache->set($key, $value, $ttl);
    }

    public function remove($key): void
    {
        $this->cache->delete($key);
    }
}
