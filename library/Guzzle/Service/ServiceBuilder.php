<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service;

use Guzzle\Common\Cache\CacheAdapterInterface;

/**
 * Service builder to generate service builders and service clients from
 * configuration settings
 *
 * @author  michael@guzzlephp.org
 */
class ServiceBuilder
{
    /**
     * @var array Service builder configuration data
     */
    protected $builderConfig = array();

    /**
     * @var array Instantiated client objects
     */
    protected $clients = array();

    /**
     * @var CacheAdapterInterface Cache adapter to use for Service caching
     */
    protected $cache;

    /**
     * @var int Cache entry TTL
     */
    protected $ttl;

    /**
     * Create a new ServiceBuilder using an XML configuration file to configure
     * the registered ServiceBuilder builder objects
     *
     * @param string $filename Full path to the XML configuration file
     * @param CacheAdapterInterface $cacheAdapter (optional) Pass a cache
     *      adapter to cache the service configuration settings loaded from the
     *      XML and to cache dynamically built services.
     * @param int $ttl (optional) How long to cache items in the cache
     *      adapter (defaults to 24 hours).
     *
     * @return ServiceBuilder
     * @throws RuntimeException if the file cannot be openend
     * @throws LogicException when trying to extend a missing client
     */
    public static function factory($filename, CacheAdapterInterface $cacheAdapter = null, $ttl = 86400)
    {
        // Compute the cache key for this service and check if it exists in cache
        $key = 'guz_service_' . md5($filename);
        $cached = $cacheAdapter ? $cacheAdapter->fetch($key) : false;

        if ($cached) {

            // Load the config from cache
            $config = unserialize($cached);

        } else {

            // Build the service config from the XML file if the file exists
            if (!is_file($filename)) {
                throw new \RuntimeException('Unable to open service configuration file ' . $filename);
            }

            $config = array();
            $xml = new \SimpleXMLElement($filename, null, true);

            // Create a client entry for each client in the XML file
            foreach ($xml->clients->client as $client) {

                $row = array();
                $name = (string) $client->attributes()->name;
                $class = (string) $client->attributes()->class;

                // Check if this client builder extends another client
                if ($extends = (string) $client->attributes()->extends) {
                    // Make sure that the service it's extending has been defined
                    if (!isset($config[$extends])) {
                        throw new \LogicException($name . ' is trying to extend a non-existent or not yet defined service: ' . $extends);
                    }

                    $class = $class ?: $config[$extends]['class'];
                    $row = $config[$extends]['params'];
                }

                // Add attributes to the row's parameters
                foreach ($client->param as $param) {
                    $row[(string) $param->attributes()->name] = (string) $param->attributes()->value;
                }

                // Add this client builder
                $config[$name] = array(
                    'class' => str_replace('.', '\\', $class),
                    'params' => $row
                );
            }

            if ($cacheAdapter) {
                $cacheAdapter->save($key, serialize($config), $ttl);
            }
        }

        $builder = new self($config);
        if ($cacheAdapter) {
            // Always share the cache
            $builder->setCache($cacheAdapter, $ttl);
        }

        return $builder;
    }

    /**
     * Construct a new service builder
     *
     * @param array $serviceBuilderConfig Service configuration settings:
     *      name => Name of the service
     *      class => Builder class used to create clients using dot notation (Guzzle.Service.Aws.S3builder or Guzzle.Service.Builder.DefaultBuilder)
     *      params => array of key value pair configuration settings for the builder
     */
    public function __construct(array $serviceBuilderConfig)
    {
        $this->builderConfig = $serviceBuilderConfig;
    }

    /**
     * Set the CacheAdapter to pass to generated builders which will allow the
     * builders to access the CacheAdapter.  This is helpul for speeding up
     * the process of parsing and loading dynamically generated clients.
     *
     * @param CacheAdapterInterface $cacheAdapter (optional) Pass a cache
     *      adapter to cache the service configuration settings loaded from the
     *      XML and to cache dynamically built services.
     * @param int $ttl (optional) How long to cache items in the cache
     *      adapter (defaults to 24 hours).
     *
     * @return ServiceBuilder
     */
    public function setCache(CacheAdapterInterface $cacheAdapter, $ttl = 86400)
    {
        $this->cache = $cacheAdapter;
        $this->ttl = $ttl ?: 86400;

        return $this;
    }

    /**
     * Get a client using a registered builder
     *
     * @param $name Name of the registered client to retrieve
     * @param bool $throwAway (optional) Set to TRUE to not store the client
     *     for later retrieval from the ServiceBuilder
     *
     * @return Client
     * @throws InvalidArgumentException when a client cannot be found by name
     */
    public function get($name, $throwAway = false)
    {
        if (!isset($this->builderConfig[$name])) {
            throw new \InvalidArgumentException('No client is registered as ' . $name);
        }

        if (!$throwAway && isset($this->clients[$name])) {
            return $this->clients[$name];
        }

        $client = call_user_func(
            array($this->builderConfig[$name]['class'], 'factory'),
            $this->builderConfig[$name]['params']
        );

        if (!$throwAway) {
            $this->clients[$name] = $client;
        }

        return $client;
    }
}