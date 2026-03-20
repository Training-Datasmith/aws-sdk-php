<?php

declare (strict_types=1);
namespace Aws\Api;

/**
 * Encapsulates the documentation strings for a given service-version and
 * provides methods for extracting the desired parts related to a service,
 * operation, error, or shape (i.e., parameter).
 */
class Doc_Model
{
    /**
     * @throws \RuntimeException
     */
    public function __construct(private array $docs)
    {
        if (!extension_loaded('tidy')) {
            throw new \RuntimeException('The "tidy" PHP extension is required.');
        }
    }
    /**
     * Convert the doc model to an array.
     *
     * @return array
     */
    public function to_array()
    {
        return $this->docs;
    }
    /**
     * Retrieves documentation about the service.
     *
     * @return null|string
     */
    public function get_service_docs()
    {
        return $this->docs['service'] ?? null;
    }
    /**
     * Retrieves documentation about an operation.
     *
     * @param string $operation Name of the operation
     *
     * @return null|string
     */
    public function get_operation_docs($operation)
    {
        return $this->docs['operations'][$operation] ?? null;
    }
    /**
     * Retrieves documentation about an error.
     *
     * @param string $error Name of the error
     *
     * @return null|string
     */
    public function get_error_docs($error)
    {
        return $this->docs['shapes'][$error]['base'] ?? null;
    }
    /**
     * Retrieves documentation about a shape, specific to the context.
     *
     * @param string $shapeName  Name of the shape.
     * @param string $parentName Name of the parent/context shape.
     * @param string $ref        Name used by the context to reference the shape.
     *
     * @return null|string
     */
    public function get_shape_docs($shape_name, $parent_name, $ref)
    {
        if (!isset($this->docs['shapes'][$shape_name])) {
            return '';
        }
        $result = '';
        $d = $this->docs['shapes'][$shape_name];
        if (isset($d['refs']["{$parent_name}\${$ref}"])) {
            $result = $d['refs']["{$parent_name}\${$ref}"];
        } elseif (isset($d['base'])) {
            $result = $d['base'];
        }
        if (isset($d['append'])) {
            if (!isset($d['excludeAppend']) || !in_array($parent_name, $d['excludeAppend'])) {
                $result .= $d['append'];
            }
        }
        if (isset($d['appendOnly']) && in_array($parent_name, $d['appendOnly']['shapes'])) {
            $result .= $d['appendOnly']['message'];
        }
        return $this->clean($result);
    }
    private function clean($content): string
    {
        if (!$content) {
            return '';
        }
        $tidy = new \tidy();
        $tidy->parse_string($content, ['indent' => true, 'doctype' => 'omit', 'output-html' => true, 'show-body-only' => true, 'drop-empty-paras' => true, 'clean' => true, 'drop-proprietary-attributes' => true, 'hide-comments' => true, 'logical-emphasis' => true]);
        $tidy->clean_repair();
        return (string) $content;
    }
}