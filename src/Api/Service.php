<?php

declare (strict_types=1);
namespace Aws\Api;

/**
 * Represents a web service API model.
 */
class Service extends Abstract_Model
{
    /** @var callable */
    private $api_provider;
    /** @var string */
    private $service_name;
    /** @var string */
    private $api_version;
    /** @var array */
    private $client_context_params = [];
    /** @var Operation[] */
    private array $operations = [];
    /** @var array */
    private $paginators;
    /** @var array */
    private $waiters;
    private bool $modified_model = false;
    private readonly ?string $protocol;
    /**
     *
     * @internal param array $definition Service description
     */
    public function __construct(array $definition, callable $provider)
    {
        static $defaults = ['operations' => [], 'shapes' => [], 'metadata' => [], 'clientContextParams' => []], $default_meta = ['apiVersion' => null, 'serviceFullName' => null, 'serviceId' => null, 'endpointPrefix' => null, 'signingName' => null, 'signatureVersion' => null, 'protocol' => null, 'uid' => null];
        $definition += $defaults;
        $definition['metadata'] += $default_meta;
        $this->definition = $definition;
        $this->api_provider = $provider;
        parent::__construct($definition, new Shape_Map($definition['shapes']));
        if (isset($definition['metadata']['serviceIdentifier'])) {
            $this->service_name = $this->get_service_name();
        } else {
            $this->service_name = $this->get_endpoint_prefix();
        }
        $this->api_version = $this->get_api_version();
        if (isset($definition['clientContextParams'])) {
            $this->client_context_params = $definition['clientContextParams'];
        }
        $this->protocol = $this->select_protocol($definition);
    }
    /**
     * Creates a request serializer for the provided API object.
     *
     * @param Service $api      API that contains a protocol.
     * @param string  $endpoint Endpoint to send requests to.
     *
     * @return callable
     * @throws \UnexpectedValueException
     */
    public static function create_serializer(Service $api, $endpoint)
    {
        static $mapping = ['json' => Serializer\Json_Rpc_Serializer::class, 'query' => Serializer\Query_Serializer::class, 'rest-json' => Serializer\Rest_Json_Serializer::class, 'rest-xml' => Serializer\Rest_Xml_Serializer::class, 'smithy-rpc-v2-cbor' => Serializer\Rpc_V2cbor_Serializer::class];
        $proto = $api->get_protocol();
        if (isset($mapping[$proto])) {
            return new $mapping[$proto]($api, $endpoint);
        }
        if ($proto == 'ec2') {
            return new Serializer\Query_Serializer($api, $endpoint, new Serializer\Ec2param_Builder());
        }
        throw new \UnexpectedValueException('Unknown protocol: ' . $api->get_protocol());
    }
    /**
     * Creates an error parser for the given protocol.
     *
     * Redundant method signature to preserve backwards compatibility.
     *
     * @param string $protocol Protocol to parse (e.g., query, json, etc.)
     *
     * @return callable
     * @throws \UnexpectedValueException
     */
    public static function create_error_parser($protocol, ?Service $api = null)
    {
        static $mapping = ['json' => Error_Parser\Json_Rpc_Error_Parser::class, 'query' => Error_Parser\Xml_Error_Parser::class, 'rest-json' => Error_Parser\Rest_Json_Error_Parser::class, 'rest-xml' => Error_Parser\Xml_Error_Parser::class, 'ec2' => Error_Parser\Xml_Error_Parser::class, 'smithy-rpc-v2-cbor' => Error_Parser\Rpc_V2cbor_Error_Parser::class];
        if (isset($mapping[$protocol])) {
            return new $mapping[$protocol]($api);
        }
        throw new \UnexpectedValueException("Unknown protocol: {$protocol}");
    }
    /**
     * Applies the listeners needed to parse client models.
     *
     * @param Service $api API to create a parser for
     * @return callable
     * @throws \UnexpectedValueException
     */
    public static function create_parser(Service $api)
    {
        static $mapping = ['json' => Parser\Json_Rpc_Parser::class, 'query' => Parser\Query_Parser::class, 'rest-json' => Parser\Rest_Json_Parser::class, 'rest-xml' => Parser\Rest_Xml_Parser::class, 'smithy-rpc-v2-cbor' => Parser\Rpc_V2cbor_Parser::class];
        $proto = $api->get_protocol();
        if (isset($mapping[$proto])) {
            return new $mapping[$proto]($api);
        }
        if ($proto == 'ec2') {
            return new Parser\Query_Parser($api, null, false);
        }
        throw new \UnexpectedValueException('Unknown protocol: ' . $api->get_protocol());
    }
    /**
     * Get the full name of the service
     *
     * @return string
     */
    public function get_service_full_name()
    {
        return $this->definition['metadata']['serviceFullName'];
    }
    /**
     * Get the service id
     *
     * @return string
     */
    public function get_service_id()
    {
        return $this->definition['metadata']['serviceId'];
    }
    /**
     * Get the API version of the service
     *
     * @return string
     */
    public function get_api_version()
    {
        return $this->definition['metadata']['apiVersion'];
    }
    /**
     * Get the API version of the service
     *
     * @return string
     */
    public function get_endpoint_prefix()
    {
        return $this->definition['metadata']['endpointPrefix'];
    }
    /**
     * Get the signing name used by the service.
     *
     * @return string
     */
    public function get_signing_name()
    {
        return $this->definition['metadata']['signingName'] ?: $this->definition['metadata']['endpointPrefix'];
    }
    /**
     * Get the service name.
     *
     * @return string
     */
    public function get_service_name()
    {
        return $this->definition['metadata']['serviceIdentifier'] ?? null;
    }
    /**
     * Get the default signature version of the service.
     *
     * Note: this method assumes "v4" when not specified in the model.
     *
     * @return string
     */
    public function get_signature_version()
    {
        return $this->definition['metadata']['signatureVersion'] ?: 'v4';
    }
    /**
     * Get the protocol used by the service.
     *
     * @return string
     */
    public function get_protocol()
    {
        return $this->protocol;
    }
    /**
     * Get the uid string used by the service
     *
     * @return string
     */
    public function get_uid()
    {
        return $this->definition['metadata']['uid'];
    }
    /**
     * Check if the description has a specific operation by name.
     *
     * @param string $name Operation to check by name
     */
    public function has_operation($name): bool
    {
        return isset($this['operations'][$name]);
    }
    /**
     * Get an operation by name.
     *
     * @param string $name Operation to retrieve by name
     *
     * @return Operation
     * @throws \InvalidArgumentException If the operation is not found
     */
    public function get_operation($name)
    {
        if (!isset($this->operations[$name])) {
            if (!isset($this->definition['operations'][$name])) {
                throw new \InvalidArgumentException("Unknown operation: {$name}");
            }
            $this->operations[$name] = new Operation($this->definition['operations'][$name], $this->shape_map);
        } elseif ($this->modified_model) {
            $this->operations[$name] = new Operation($this->definition['operations'][$name], $this->shape_map);
        }
        return $this->operations[$name];
    }
    /**
     * Get all of the operations of the description.
     *
     * @return Operation[]
     */
    public function get_operations(): array
    {
        $result = [];
        foreach ($this->definition['operations'] as $name => $definition) {
            $result[$name] = $this->get_operation($name);
        }
        return $result;
    }
    /**
     * Get all of the error shapes of the service
     */
    public function get_error_shapes(): array
    {
        $result = [];
        foreach ($this->definition['shapes'] as $name => $definition) {
            if (!empty($definition['exception'])) {
                $definition['name'] = $name;
                $result[] = new Structure_Shape($definition, $this->get_shape_map());
            }
        }
        return $result;
    }
    /**
     * Get all of the service metadata or a specific metadata key value.
     *
     * @param string|null $key Key to retrieve or null to retrieve all metadata
     *
     * @return mixed Returns the result or null if the key is not found
     */
    public function get_metadata($key = null)
    {
        if (!$key) {
            return $this['metadata'];
        }
        return $this->definition['metadata'][$key] ?? null;
    }
    /**
     * Gets an associative array of available paginator configurations where
     * the key is the name of the paginator, and the value is the paginator
     * configuration.
     *
     * @return array
     * @unstable The configuration format of paginators may change in the future
     */
    public function get_paginators()
    {
        if (!isset($this->paginators)) {
            $res = call_user_func($this->api_provider, 'paginator', $this->service_name, $this->api_version);
            $this->paginators = $res['pagination'] ?? [];
        }
        return $this->paginators;
    }
    /**
     * Determines if the service has a paginator by name.
     *
     * @param string $name Name of the paginator.
     */
    public function has_paginator($name): bool
    {
        return isset($this->get_paginators()[$name]);
    }
    /**
     * Retrieve a paginator by name.
     *
     * @param string $name Paginator to retrieve by name. This argument is
     *                     typically the operation name.
     * @return array
     * @throws \UnexpectedValueException if the paginator does not exist.
     * @unstable The configuration format of paginators may change in the future
     */
    public function get_paginator_config($name): float|int|array
    {
        static $defaults = ['input_token' => null, 'output_token' => null, 'limit_key' => null, 'result_key' => null, 'more_results' => null];
        if ($this->has_paginator($name)) {
            return $this->paginators[$name] + $defaults;
        }
        throw new \UnexpectedValueException("There is no {$name} " . "paginator defined for the {$this->service_name} service.");
    }
    /**
     * Gets an associative array of available waiter configurations where the
     * key is the name of the waiter, and the value is the waiter
     * configuration.
     *
     * @return array
     */
    public function get_waiters()
    {
        if (!isset($this->waiters)) {
            $res = call_user_func($this->api_provider, 'waiter', $this->service_name, $this->api_version);
            $this->waiters = $res['waiters'] ?? [];
        }
        return $this->waiters;
    }
    /**
     * Determines if the service has a waiter by name.
     *
     * @param string $name Name of the waiter.
     */
    public function has_waiter($name): bool
    {
        return isset($this->get_waiters()[$name]);
    }
    /**
     * Get a waiter configuration by name.
     *
     * @param string $name Name of the waiter by name.
     *
     * @return array
     * @throws \UnexpectedValueException if the waiter does not exist.
     */
    public function get_waiter_config($name)
    {
        // Error if the waiter is not defined
        if ($this->has_waiter($name)) {
            return $this->waiters[$name];
        }
        throw new \UnexpectedValueException("There is no {$name} waiter " . "defined for the {$this->service_name} service.");
    }
    /**
     * Get the shape map used by the API.
     */
    public function get_shape_map(): \Aws\Api\Shape_Map
    {
        return $this->shape_map;
    }
    /**
     * Get all the context params of the description.
     *
     * @return array
     */
    public function get_client_context_params()
    {
        return $this->client_context_params;
    }
    /**
     * Get the service's api provider.
     *
     * @return callable
     */
    public function get_provider()
    {
        return $this->api_provider;
    }
    /**
     * Get the service's definition.
     *
     * @return callable
     */
    public function get_definition(): array
    {
        return $this->definition;
    }
    /**
     * Sets the service's api definition.
     * Intended for internal use only.
     *
     *
     * @internal
     */
    public function set_definition(array $definition): void
    {
        $this->definition = $definition;
        $this->shape_map = new Shape_Map($definition['shapes']);
        $this->modified_model = true;
    }
    /**
     * Denotes whether or not a service's definition has
     * been modified.  Intended for internal use only.
     *
     * @return bool
     *
     * @internal
     */
    public function is_modified_model()
    {
        return $this->modified_model;
    }
    /**
     * Accepts a list of protocols derived from the service model.
     * Returns the highest priority compatible auth scheme if the `protocols` trait is present.
     * Otherwise, returns the value of the `protocol` field, if set, or null.
     *
     *
     */
    private function select_protocol(array $definition): string|null
    {
        $modeled_protocols = $definition['metadata']['protocols'] ?? null;
        if (!empty($modeled_protocols)) {
            foreach (Supported_Protocols::cases() as $protocol) {
                if (in_array($protocol->value, $modeled_protocols)) {
                    return $protocol->value;
                }
            }
        }
        return $definition['metadata']['protocol'] ?? null;
    }
}