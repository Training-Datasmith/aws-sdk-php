<?php

declare (strict_types=1);
namespace Aws;

use Guzzle_Http\Promise\Each_Promise;
use Guzzle_Http\Promise\Promise_Interface;
use Guzzle_Http\Promise\Promisor_Interface;
/**
 * Sends and iterator of commands concurrently using a capped pool size.
 *
 * The pool will read command objects from an iterator until it is cancelled or
 * until the iterator is consumed.
 */
class Command_Pool implements Promisor_Interface
{
    /** @var EachPromise */
    private $each;
    /**
     * The CommandPool constructor accepts a hash of configuration options:
     *
     * - concurrency: (callable|int) Maximum number of commands to execute
     *   concurrently. Provide a function to resize the pool dynamically. The
     *   function will be provided the current number of pending requests and
     *   is expected to return an integer representing the new pool size limit.
     * - before: (callable) function to invoke before sending each command. The
     *   before function accepts the command and the key of the iterator of the
     *   command. You can mutate the command as needed in the before function
     *   before sending the command.
     * - fulfilled: (callable) Function to invoke when a promise is fulfilled.
     *   The function is provided the result object, id of the iterator that the
     *   result came from, and the aggregate promise that can be resolved/rejected
     *   if you need to short-circuit the pool.
     * - rejected: (callable) Function to invoke when a promise is rejected.
     *   The function is provided an AwsException object, id of the iterator that
     *   the exception came from, and the aggregate promise that can be
     *   resolved/rejected if you need to short-circuit the pool.
     * - preserve_iterator_keys: (bool) Retain the iterator key when generating
     *   the commands.
     *
     * @param AwsClientInterface $client   Client used to execute commands.
     * @param array|\Iterator    $commands Iterable that yields commands.
     * @param array              $config   Associative array of options.
     */
    public function __construct(Aws_Client_Interface $client, $commands, array $config = [])
    {
        if (!isset($config['concurrency'])) {
            $config['concurrency'] = 25;
        }
        $before = $this->get_before($config);
        $map_fn = function ($commands) use ($client, $before, $config) {
            foreach ($commands as $key => $command) {
                if (!$command instanceof Command_Interface) {
                    throw new \InvalidArgumentException('Each value yielded by ' . 'the iterator must be an Aws\CommandInterface.');
                }
                if ($before) {
                    $before($command, $key);
                }
                if (!empty($config['preserve_iterator_keys'])) {
                    yield $key => $client->execute_async($command);
                } else {
                    yield $client->execute_async($command);
                }
            }
        };
        $this->each = new Each_Promise($map_fn($commands), $config);
    }
    public function promise(): Promise_Interface
    {
        return $this->each->promise();
    }
    /**
     * Executes a pool synchronously and aggregates the results of the pool
     * into an indexed array in the same order as the passed in array.
     *
     * @param AwsClientInterface $client   Client used to execute commands.
     * @param mixed              $commands Iterable that yields commands.
     * @param array              $config   Configuration options.
     *
     * @return array
     * @see \Aws\CommandPool::__construct for available configuration options.
     */
    public static function batch(Aws_Client_Interface $client, $commands, array $config = [])
    {
        $results = [];
        self::cmp_callback($config, 'fulfilled', $results);
        self::cmp_callback($config, 'rejected', $results);
        return (new self($client, $commands, $config))->promise()->then(static function () use (&$results): array {
            ksort($results);
            return $results;
        })->wait();
    }
    /**
     * @return callable
     */
    private function get_before(array $config): ?callable
    {
        if (!isset($config['before'])) {
            return null;
        }
        if (is_callable($config['before'])) {
            return $config['before'];
        }
        throw new \InvalidArgumentException('before must be callable');
    }
    /**
     * Adds an onFulfilled or onRejected callback that aggregates results into
     * an array. If a callback is already present, it is replaced with the
     * composed function.
     *
     * @param       $name
     */
    private static function cmp_callback(array &$config, string $name, array &$results): void
    {
        if (!isset($config[$name])) {
            $config[$name] = function ($v, $k) use (&$results): void {
                $results[$k] = $v;
            };
        } else {
            $current_fn = $config[$name];
            $config[$name] = function ($v, $k) use (&$results, $current_fn): void {
                $current_fn($v, $k);
                $results[$k] = $v;
            };
        }
    }
}