<?php

namespace Aws\EndpointV2\Ruleset;

/**
 * Represents a fully resolved endpoint that a
 * rule returns if input parameters meet its requirements.
 */
class RulesetEndpoint
{
    /**
     * @param string $url
     * @param mixed[] $properties
     * @param mixed[] $headers
     */
    public function __construct(private $url, private $properties = null, private $headers = null)
    {
    }

    /**
     * @return mixed
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * @param $property
     * @return mixed
     */
    public function getProperty($property)
    {
        return $this->properties[$property] ?? null;
    }

    /**
     * @return mixed
     */
    public function getProperties()
    {
        return $this->properties;
    }

    /**
     * @return mixed
     */
    public function getHeaders()
    {
        return $this->headers;
    }
}
