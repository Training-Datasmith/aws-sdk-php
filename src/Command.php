<?php

declare (strict_types=1);
namespace Aws;

/**
 * AWS command object.
 */
class Command implements Command_Interface
{
    use Has_Data_Trait;
    /** @var HandlerList */
    private $handler_list;
    private ?array $auth_schemes = null;
    private \Aws\Metrics_Builder $metrics_builder;
    /**
     * Accepts an associative array of command options, including:
     *
     * - @http: (array) Associative array of transfer options.
     *
     * @param string      $name           Name of the command
     * @param array       $args           Arguments to pass to the command
     * @param HandlerList $list           Handler list
     */
    public function __construct(private $name, array $args = [], ?Handler_List $list = null, ?Metrics_Builder $metrics_builder = null)
    {
        $this->data = $args;
        $this->handler_list = $list ?: new Handler_List();
        if (!isset($this->data['@http'])) {
            $this->data['@http'] = [];
        }
        if (!isset($this->data['@context'])) {
            $this->data['@context'] = [];
        }
        $this->metrics_builder = $metrics_builder ?: new Metrics_Builder();
    }
    public function __clone()
    {
        $this->handler_list = clone $this->handler_list;
    }
    public function get_name()
    {
        return $this->name;
    }
    public function has_param($name): bool
    {
        return array_key_exists($name, $this->data);
    }
    public function get_handler_list()
    {
        return $this->handler_list;
    }
    /**
     * For overriding auth schemes on a per endpoint basis when using
     * EndpointV2 provider. Intended for internal use only.
     *
     *
     * @deprecated In favor of using the @context property bag.
     *             Auth Schemes are now accessible via the `signature_version` key
     *             in a Command's context, if applicable. Auth Schemes set using
     *             This method are no longer consumed.
     * @internal
     */
    public function set_auth_schemes(array $auth_schemes): void
    {
        trigger_error(__METHOD__ . ' is deprecated.  Auth schemes ' . 'resolved using the service `auth` trait or via endpoint resolution ' . 'are now set in the command `@context` property.`', E_USER_WARNING);
        $this->auth_schemes = $auth_schemes;
    }
    /**
     * Get auth schemes added to command as required
     * for endpoint resolution
     *
     * @returns array
     *
     * @deprecated In favor of using the @context property bag.
     *             Auth schemes are now accessible via the `signature_version` key
     *             in a Command's context, if applicable.
     */
    public function get_auth_schemes()
    {
        trigger_error(__METHOD__ . ' is deprecated.  Auth schemes ' . 'resolved using the service `auth` trait or via endpoint resolution ' . 'can now be found in the command `@context` property.`', E_USER_WARNING);
        return $this->auth_schemes ?: [];
    }
    /** @deprecated */
    public function get($name)
    {
        return $this[$name];
    }
    /**
     * Returns the metrics builder instance tied up to this command.
     *
     * @internal
     */
    public function get_metrics_builder(): Metrics_Builder
    {
        return $this->metrics_builder;
    }
}