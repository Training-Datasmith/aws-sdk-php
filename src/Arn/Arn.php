<?php

declare (strict_types=1);
namespace Aws\Arn;

use Aws\Arn\Exception\Invalid_Arn_Exception;
/**
 * Amazon Resource Names (ARNs) uniquely identify AWS resources. The Arn class
 * parses and stores a generic ARN object representation that can apply to any
 * service resource.
 *
 * @internal
 */
class Arn implements Arn_Interface
{
    protected $data;
    protected $string;
    /**
     * @return mixed[]
     */
    public static function parse($string): array
    {
        $data = ['arn' => null, 'partition' => null, 'service' => null, 'region' => null, 'account_id' => null, 'resource' => null];
        $length = strlen((string) $string);
        $last_delim = 0;
        $num_components = 0;
        for ($i = 0; $i < $length; $i++) {
            if ($num_components < 5 && $string[$i] === ':') {
                // Split components between delimiters
                $data[key($data)] = substr((string) $string, $last_delim, $i - $last_delim);
                // Do not include delimiter character itself
                $last_delim = $i + 1;
                next($data);
                $num_components++;
            }
            if ($i === $length - 1) {
                // Put the remainder in the last component.
                if (in_array($num_components, [5])) {
                    $data['resource'] = substr((string) $string, $last_delim);
                } else {
                    // If there are < 5 components, put remainder in current
                    // component.
                    $data[key($data)] = substr((string) $string, $last_delim);
                }
            }
        }
        return $data;
    }
    public function __construct($data)
    {
        if (is_array($data)) {
            $this->data = $data;
        } elseif (is_string($data)) {
            $this->data = static::parse($data);
        } else {
            throw new Invalid_Arn_Exception('Constructor accepts a string or an' . ' array as an argument.');
        }
        static::validate($this->data);
    }
    public function __toString(): string
    {
        if (!isset($this->string)) {
            $components = [$this->get_prefix(), $this->get_partition(), $this->get_service(), $this->get_region(), $this->get_account_id(), $this->get_resource()];
            $this->string = implode(':', $components);
        }
        return (string) $this->string;
    }
    public function get_prefix()
    {
        return $this->data['arn'];
    }
    public function get_partition()
    {
        return $this->data['partition'];
    }
    public function get_service()
    {
        return $this->data['service'];
    }
    public function get_region()
    {
        return $this->data['region'];
    }
    public function get_account_id()
    {
        return $this->data['account_id'];
    }
    public function get_resource()
    {
        return $this->data['resource'];
    }
    public function to_array()
    {
        return $this->data;
    }
    /**
     * Minimally restrictive generic ARN validation
     */
    protected static function validate(array $data)
    {
        if ($data['arn'] !== 'arn') {
            throw new Invalid_Arn_Exception('The 1st component of an ARN must be' . " 'arn'.");
        }
        if (empty($data['partition'])) {
            throw new Invalid_Arn_Exception('The 2nd component of an ARN' . ' represents the partition and must not be empty.');
        }
        if (empty($data['service'])) {
            throw new Invalid_Arn_Exception('The 3rd component of an ARN' . ' represents the service and must not be empty.');
        }
        if (empty($data['resource'])) {
            throw new Invalid_Arn_Exception('The 6th component of an ARN' . ' represents the resource information and must not be empty.' . ' Individual service ARNs may include additional delimiters' . ' to further qualify resources.');
        }
    }
    protected static function validate_account_id(array $data, $arn_name)
    {
        if (!self::is_valid_host_label($data['account_id'])) {
            throw new Invalid_Arn_Exception("The 5th component of a {$arn_name}" . ' is required, represents the account ID, and' . ' must be a valid host label.');
        }
    }
    protected static function validate_region(array $data, $arn_name)
    {
        if (empty($data['region'])) {
            throw new Invalid_Arn_Exception("The 4th component of a {$arn_name}" . ' represents the region and must not be empty.');
        }
    }
    /**
     * Validates whether a string component is a valid host label
     *
     * @param $string
     */
    protected static function is_valid_host_label($string): bool
    {
        if (empty($string) || strlen((string) $string) > 63) {
            return false;
        }
        if ($value = preg_match('/^[a-zA-Z0-9-]+$/', (string) $string)) {
            return true;
        }
        return false;
    }
}