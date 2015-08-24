<?php

namespace Framework\Redis;

use Closure;
use Framework\Config;
use Predis\Client;

/**
 * Class Redis
 * @package Framework\Redis
 *
 * @method static mixed get( string $key )
 * @method static mixed set( string $key, mixed $value)
 * @method static mixed setex( string $key, int $ex, mixed $value)
 * @method static int del(string $key)
 * @method static array pipeline(Closure $f)
 * @method static array zremrangebyscore(string $key, $min, $max)
 * @method static array zrangebyscore(string $key, $min, $max)
 * @method static int zadd(string $key, $score, $value)
 */

class Redis
{
    /**
     * The host address of the database.
     *
     * @var array
     */
    protected $clients;
    /**
     * Create a new Redis connection instance.
     *
     * @param  array  $servers
     * @return void
     */
    public function __construct(array $servers = [])
    {
        $cluster = Config::get('redis.cluster');
        $options = (array) Config::get('redis.options', array());
        if ($cluster) {
            $this->clients = $this->createAggregateClient($servers, $options);
        } else {
            $this->clients = $this->createSingleClients($servers, $options);
        }
    }
    /**
     * Create a new aggregate client supporting sharding.
     *
     * @param  array  $servers
     * @param  array  $options
     * @return array
     */
    protected function createAggregateClient(array $servers, array $options = [])
    {
        return ['default' => new Client(array_values($servers), $options)];
    }
    /**
     * Create an array of single connection clients.
     *
     * @param  array  $servers
     * @param  array  $options
     * @return array
     */
    protected function createSingleClients(array $servers, array $options = [])
    {
        $clients = [];
        foreach ($servers as $key => $server) {
            $clients[$key] = new Client($server, $options);
        }
        return $clients;
    }
    /**
     * Get a specific Redis connection instance.
     *
     * @param  string  $name
     * @return \Predis\ClientInterface|null
     */
    public function connection($name = 'default')
    {
        if ( ! isset($this) ) return self::getInstance()->clients[Config::get("redis.$name")];
        return $this->clients[ Config::get("redis.$name") ];
    }
    /**
     * Run a command against the Redis database.
     *
     * @param  string  $method
     * @param  array   $parameters
     * @return mixed
     */
    public function command($method, array $parameters = [])
    {
        return call_user_func_array([$this->clients['default'], $method], $parameters);
    }
    /**
     * Subscribe to a set of given channels for messages.
     *
     * @param  array|string  $channels
     * @param  \Closure  $callback
     * @param  string  $connection
     * @param  string  $method
     */
    public function subscribe($channels, Closure $callback, $connection = 'default', $method = 'subscribe')
    {
        $loop = $this->clients[$connection]->pubSubLoop();
        call_user_func_array([$loop, $method], (array) $channels);
        foreach ($loop as $message) {
            if ($message->kind === 'message' || $message->kind === 'pmessage') {
                call_user_func($callback, $message->payload, $message->channel);
            }
        }
        unset($loop);
    }
    /**
     * Subscribe to a set of given channels with wildcards.
     *
     * @param  array|string  $channels
     * @param  \Closure  $callback
     * @param  string  $connection
     */
    public function psubscribe($channels, Closure $callback, $connection = 'default')
    {
         $this->subscribe($channels, $callback, $connection, __FUNCTION__);
    }

    public static function __callStatic( $method, $parameters ) {
        return self::getInstance()->command($method, $parameters);
    }

    /**
     * Dynamically make a Redis command.
     *
     * @param  string  $method
     * @param  array   $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->command($method, $parameters);
    }



    private static $myself;

    public static function getInstance( ) {
        if ( self::$myself == null )
            self::$myself = new Redis( Config::get('redis.servers') );
        return self::$myself;
    }
}