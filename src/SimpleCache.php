<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace Yiisoft\Cache;

use Psr\SimpleCache\CacheInterface;
use yii\base\Component;
use yii\helpers\Yii;
use Yiisoft\Cache\Exceptions\InvalidConfigException;
use Yiisoft\Cache\Serializer\PhpSerializer;
use Yiisoft\Cache\Serializer\SerializerInterface;

/**
 * SimpleCache is the base class for cache classes implementing pure PSR-16 [[CacheInterface]].
 * This class handles cache key normalization, default TTL specification normalization, data serialization.
 *
 * Derived classes should implement the following methods which do the actual cache storage operations:
 *
 * - [[getValue()]]: retrieve the value with a key (if any) from cache
 * - [[setValue()]]: store the value with a key into cache
 * - [[deleteValue()]]: delete the value with the specified key from cache
 * - [[clear()]]: delete all values from cache
 *
 * For more details and usage information on Cache, see the [guide article on caching](guide:caching-overview)
 * and [PSR-16 specification](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-16-simple-cache.md).
 *
 * @see CacheInterface
 */
abstract class SimpleCache extends Component implements CacheInterface
{
    /**
     * @var int default TTL for a cache entry. Default value is 0, meaning infinity.
     * This value is used by [[set()]] and [[setMultiple()]], if the duration is not explicitly given.
     */
    public $defaultTtl = 0;
    /**
     * @var string a string prefixed to every cache key so that it is unique globally in the whole cache storage.
     * It is recommended that you set a unique cache key prefix for each application if the same cache
     * storage is being used by different applications.
     *
     * To ensure interoperability, only alphanumeric characters should be used.
     */
    public $keyPrefix = '';
    /**
     * @var SerializerInterface the serializer to be used for serializing and unserializing of the cached data.
     *
     * You can disable serialization by setting this property to `NullSerializer`,
     * data will be directly sent to and retrieved from the underlying
     * cache component without any serialization or deserialization. You should not turn off serialization if
     * you are using [[Dependency|cache dependency]], because it relies on data serialization. Also, some
     * implementations of the cache can not correctly save and retrieve data different from a string type.
     */
    protected $_serializer;


    /**
     * @var SerializerInterface the serializer to be used for serializing and unserializing of the cached data.
     * Serializer should be an instance of [[SerializerInterface]] or its DI compatible configuration.
     * @see setSerializer
     */
    public function __construct(SerializerInterface $serializer = null)
    {
        $this->setSerializer($serializer);
    }

    /**
     * @var SerializerInterface the serializer to be used for serializing and unserializing of the cached data.
     *
     * Default is [[PhpSerializer]], meaning using the default PHP `serialize()` and `unserialize()` functions.
     */
    public function setSerializer(SerializerInterface $serializer = null)
    {
        $this->_serializer = $serializer ?? new PhpSerializer();
    }

    /**
     * {@inheritdoc}
     */
    public function get($key, $default = null)
    {
        $key = $this->normalizeKey($key);
        $value = $this->getValue($key);
        if ($value === false) {
            return $default;
        }

        return $this->_serializer->unserialize($value);
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple($keys, $default = null)
    {
        $keyMap = [];
        foreach ($keys as $key) {
            $keyMap[$key] = $this->normalizeKey($key);
        }
        $values = $this->getValues(array_values($keyMap));
        $results = [];
        foreach ($keyMap as $key => $newKey) {
            $results[$key] = $default;
            if (isset($values[$newKey]) && $values[$newKey] !== false) {
                $results[$key] = $this->_serializer->unserialize($values[$newKey]);
            }
        }
        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function has($key): bool
    {
        $key = $this->normalizeKey($key);
        $value = $this->getValue($key);
        return $value !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function set($key, $value, $ttl = null): bool
    {
        $value = $this->_serializer->serialize($value);
        $key = $this->normalizeKey($key);
        $ttl = $this->normalizeTtl($ttl);
        return $this->setValue($key, $value, $ttl);
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple($values, $ttl = null): bool
    {
        $data = [];
        foreach ($values as $key => $value) {
            $value = $this->_serializer->serialize($value);
            $key = $this->normalizeKey($key);
            $data[$key] = $value;
        }
        return $this->setValues($data, $this->normalizeTtl($ttl));
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key): bool
    {
        $key = $this->normalizeKey($key);
        return $this->deleteValue($key);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple($keys): bool
    {
        $result = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $result = false;
            }
        }
        return $result;
    }

    /**
     * Builds a normalized cache key from a given key.
     *
     * The given key will be type-casted to string.
     * If the result string does not contain alphanumeric characters only or has more than 32 characters,
     * then the hash of the key will be used.
     * The result key will be returned back prefixed with [[keyPrefix]].
     *
     * @param mixed $key the key to be normalized
     * @return string the generated cache key
     */
    protected function normalizeKey($key): string
    {
        $key = (string)$key;
        $key = ctype_alnum($key) && \strlen($key) <= 32 ? $key : md5($key);
        return $this->keyPrefix . $key;
    }

    /**
     * Normalizes cache TTL handling `null` value and [[\DateInterval]] objects.
     * @param int|\DateInterval|null $ttl raw TTL.
     * @return int|float TTL value as UNIX timestamp.
     */
    protected function normalizeTtl($ttl)
    {
        if ($ttl === null) {
            return $this->defaultTtl;
        }
        if ($ttl instanceof \DateInterval) {
            return (new \DateTime('@0'))->add($ttl)->getTimestamp();
        }
        return $ttl;
    }

    /**
     * Retrieves a value from cache with a specified key.
     * This method should be implemented by child classes to retrieve the data
     * from specific cache storage.
     * @param string $key a unique key identifying the cached value
     * @return mixed|false the value stored in cache, `false` if the value is not in the cache or expired. Most often
     * value is a string. If you have disabled [[serializer]], it could be something else.
     */
    abstract protected function getValue($key);

    /**
     * Stores a value identified by a key in cache.
     * This method should be implemented by child classes to store the data
     * in specific cache storage.
     * @param string $key the key identifying the value to be cached
     * @param mixed $value the value to be cached. Most often it's a string. If you have disabled [[serializer]],
     * it could be something else.
     * @param int $ttl the number of seconds in which the cached value will expire.
     * @return bool true if the value is successfully stored into cache, false otherwise
     */
    abstract protected function setValue($key, $value, $ttl): bool;

    /**
     * Deletes a value with the specified key from cache
     * This method should be implemented by child classes to delete the data from actual cache storage.
     * @param string $key the key of the value to be deleted
     * @return bool if no error happens during deletion
     */
    abstract protected function deleteValue($key): bool;

    /**
     * Retrieves multiple values from cache with the specified keys.
     * The default implementation calls [[getValue()]] multiple times to retrieve
     * the cached values one by one. If the underlying cache storage supports multiget,
     * this method should be overridden to exploit that feature.
     * @param array $keys a list of keys identifying the cached values
     * @return array a list of cached values indexed by the keys
     */
    protected function getValues($keys): array
    {
        $results = [];
        foreach ($keys as $key) {
            $value = $this->getValue($key);
            if ($value !== false) {
                $results[$key] = $value;
            }
        }
        return $results;
    }

    /**
     * Stores multiple key-value pairs in cache.
     * The default implementation calls [[setValue()]] multiple times store values one by one. If the underlying cache
     * storage supports multi-set, this method should be overridden to exploit that feature.
     * @param array $values array where key corresponds to cache key while value is the value stored
     * @param int $ttl the number of seconds in which the cached values will expire.
     * @return bool `true` on success and `false` on failure.
     */
    protected function setValues($values, $ttl): bool
    {
        $result = true;
        foreach ($values as $key => $value) {
            if ($this->setValue($key, $value, $ttl) === false) {
                $result = false;
            }
        }
        return $result;
    }
}
