<?php
namespace Aws\Endpoint;

use JmesPath\Env;

class PartitionEndpointProvider
{
    /** @var Partition[] */
    private readonly array $partitions;

    /**
     * The 'options' parameter accepts the following arguments:
     *
     * - sts_regional_endpoints: For STS legacy regions, set to 'regional' to
     *   use regional endpoints, 'legacy' to use the legacy global endpoint.
     *   Defaults to 'legacy'.
     * - s3_us_east_1_regional_endpoint: For S3 us-east-1 region, set to 'regional'
     *   to use the regional endpoint, 'legacy' to use the legacy global endpoint.
     *   Defaults to 'legacy'.
     *
     * @param string $defaultPartition
     * @param array $options
     */
    public function __construct(
        array $partitions,
        private $defaultPartition = 'aws',
        private $options = []
    ) {
        $this->partitions = array_map(fn(array $definition) => new Partition($definition), array_values($partitions));
    }

    public function __invoke(array $args = [])
    {
        $partition = $this->getPartition(
            $args['region'] ?? '',
            $args['service'] ?? ''
        );
        $args['options'] = $this->options;

        return $partition($args);
    }

    /**
     * Returns the partition containing the provided region or the default
     * partition if no match is found.
     *
     * @param string $region
     * @param string $service
     *
     * @return Partition
     */
    public function getPartition($region, $service)
    {
        foreach ($this->partitions as $partition) {
            if ($partition->isRegionMatch($region, $service)) {
                return $partition;
            }
        }

        return $this->getPartitionByName($this->defaultPartition);
    }

    /**
     * Returns the partition with the provided name or null if no partition with
     * the provided name can be found.
     *
     * @param string $name
     *
     * @return Partition|null
     */
    public function getPartitionByName($name)
    {
        foreach ($this->partitions as $partition) {
            if ($name === $partition->getName()) {
                return $partition;
            }
        }
    }

    /**
     * Creates and returns the default SDK partition provider.
     *
     * @param array $options
     */
    public static function defaultProvider($options = []): self
    {
        $data = \Aws\load_compiled_json(__DIR__ . '/../data/endpoints.json');
        $prefixData = \Aws\load_compiled_json(__DIR__ . '/../data/endpoints_prefix_history.json');
        $mergedData = self::mergePrefixData($data, $prefixData);

        return new self($mergedData['partitions'], 'aws', $options);
    }

    /**
     * Copy endpoint data for other prefixes used by a given service
     *
     * @param $data
     * @param $prefixData
     */
    public static function mergePrefixData(array $data, array $prefixData): array
    {
        $prefixGroups = $prefixData['prefix-groups'];

        foreach ($data["partitions"] as $index => $partition) {
            foreach ($prefixGroups as $current => $old) {
                $serviceData = Env::search("services.\"{$current}\"", $partition);
                if (!empty($serviceData)) {
                    foreach ($old as $prefix) {
                        if (empty(Env::search("services.\"{$prefix}\"", $partition))) {
                            $data["partitions"][$index]["services"][$prefix] = $serviceData;
                        }
                    }
                }
            }
        }

        return $data;
    }
}
