<?php

declare (strict_types=1);
namespace Aws\Client_Side_Monitoring;

class Configuration implements Configuration_Interface
{
    private readonly string $client_id;
    private readonly bool $enabled;
    private readonly int|bool $port;
    /**
     * Constructs a new Configuration object with the specified CSM options set.
     *
     * @param mixed $enabled
     * @param string $host
     * @param string|int $port
     * @param string $clientId
     */
    public function __construct($enabled, private $host, $port, $client_id = '')
    {
        $this->port = filter_var($port, FILTER_VALIDATE_INT);
        if ($this->port === false) {
            throw new \InvalidArgumentException("CSM 'port' value must be an integer!");
        }
        // Unparsable $enabled flag errors on the side of disabling CSM
        $this->enabled = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
        $this->client_id = trim($client_id);
    }
    /**
     * {@inheritdoc}
     */
    public function is_enabled()
    {
        return $this->enabled;
    }
    /**
     * {@inheritdoc}
     */
    public function get_client_id()
    {
        return $this->client_id;
    }
    /**
     * /{@inheritdoc}
     */
    public function get_host()
    {
        return $this->host;
    }
    /**
     * {@inheritdoc}
     */
    public function get_port()
    {
        return $this->port;
    }
    /**
     * {@inheritdoc}
     */
    public function to_array(): array
    {
        return ['client_id' => $this->get_client_id(), 'enabled' => $this->is_enabled(), 'host' => $this->get_host(), 'port' => $this->get_port()];
    }
}