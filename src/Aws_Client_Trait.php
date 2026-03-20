<?php

declare (strict_types=1);
namespace Aws;

use Aws\Api\Service;
/**
 * A trait providing generic functionality for interacting with Amazon Web
 * Services. This is meant to be used in classes implementing
 * \Aws\AwsClientInterface
 */
trait Aws_Client_Trait
{
    public function get_paginator($name, array $args = []): \Aws\Result_Paginator
    {
        $config = $this->get_api()->get_paginator_config($name);
        return new Result_Paginator($this, $name, $args, $config);
    }
    public function getIterator(string $name, array $args = [])
    {
        $config = $this->get_api()->get_paginator_config($name);
        if (!$config['result_key']) {
            throw new \UnexpectedValueException(sprintf('There are no resources to iterate for the %s operation of %s', $name, $this->get_api()['serviceFullName']));
        }
        $key = is_array($config['result_key']) ? $config['result_key'][0] : $config['result_key'];
        if ($config['output_token'] && $config['input_token']) {
            return $this->get_paginator($name, $args)->search($key);
        }
        $result = $this->execute($this->get_command($name, $args))->search($key);
        return new \ArrayIterator((array) $result);
    }
    public function wait_until($name, array $args = [])
    {
        return $this->get_waiter($name, $args)->promise()->wait();
    }
    public function get_waiter($name, array $args = []): \Aws\Waiter
    {
        $config = $args['@waiter'] ?? [];
        $config += $this->get_api()->get_waiter_config($name);
        return new Waiter($this, $name, $args, $config);
    }
    public function execute(Command_Interface $command)
    {
        return $this->execute_async($command)->wait();
    }
    public function execute_async(Command_Interface $command)
    {
        $handler = $command->get_handler_list()->resolve();
        return $handler($command);
    }
    public function __call($name, array $args)
    {
        if (str_ends_with((string) $name, 'Async')) {
            $name = substr((string) $name, 0, -5);
            $is_async = true;
        }
        if (!empty($this->aliases[ucfirst((string) $name)])) {
            $name = $this->aliases[ucfirst((string) $name)];
        }
        $params = $args['args'] ?? $args[0] ?? [];
        if (!empty($is_async)) {
            return $this->execute_async($this->get_command($name, $params));
        }
        return $this->execute($this->get_command($name, $params));
    }
    /**
     * @param string $name
     *
     * @return CommandInterface
     */
    abstract public function get_command($name, array $args = []);
    /**
     * @return Service
     */
    abstract public function get_api();
}