<?php namespace Atrauzzi\LaravelDoctrine;

use Doctrine\Common\Cache\ApcCache;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Cache\CouchbaseCache;
use Doctrine\Common\Cache\MemcacheCache;
use Doctrine\Common\Cache\MemcachedCache;
use Doctrine\Common\Cache\RedisCache;
use Doctrine\Common\Cache\XcacheCache;
use Illuminate\Config\Repository;
use Illuminate\Support\ServiceProvider as Base;
use Doctrine\Common\EventManager;
use Doctrine\ORM\Tools\Setup;
use Doctrine\ORM\Events;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Mapping\Driver\DriverChain;


class ServiceProvider extends Base {

	/**
	 * Bootstrap the application events.
	 *
	 * @return void
	 */
	public function boot() {
		$this->publishes(
			[
				realpath(__DIR__ .'/../../config/doctrine.php') => config_path('doctrine.php')
			]);
	}

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register() {

		//
		// Doctrine
		//
		$this->app->singleton('Doctrine\ORM\EntityManager', function ($app) {

			// Retrieve our configuration.
			/** @var Repository $config */
			$config = $app['config'];
			$connection = $config->get('laravel-doctrine::doctrine.connection');
			$devMode = $config->get('laravel-doctrine::doctrine.devMode', $config->get('app.debug'));

			$doctrine_config = Setup::createConfiguration(
				$devMode,
				$config->get('laravel-doctrine::doctrine.proxy_classes.directory'),
				NULL
			);

			$annotation_driver = $doctrine_config->newDefaultAnnotationDriver(
				$config->get('laravel-doctrine::doctrine.metadata'),
				$config->get('laravel-doctrine::doctrine.use_simple_annotation_reader')
			);

			if ($config->get('laravel-doctrine::doctrine.driverChain.enabled')) {
				$driver_chain = new DriverChain();
				$driver_chain->addDriver($annotation_driver, $config->get('laravel-doctrine::doctrine.driverChain.defaultNamespace'));
				$doctrine_config->setMetadataDriverImpl($driver_chain);
			} else {
				$doctrine_config->setMetadataDriverImpl($annotation_driver);
			}
                        
			/*
			 * set cache implementations
			 * must occur after Setup::createAnnotationMetadataConfiguration() in order to set custom namespaces properly
			 */
			if(!$devMode) {
				$defaultCacheProvider = $this->getCacheProvider($config, 'default');

				$doctrine_config->setMetadataCacheImpl($this->getCacheProvider($config, 'metadata') ?: $defaultCacheProvider);
				$doctrine_config->setQueryCacheImpl($this->getCacheProvider($config, 'query') ?: $defaultCacheProvider);
				$doctrine_config->setResultCacheImpl($this->getCacheProvider($config, 'result') ?: $defaultCacheProvider);
			}


			$doctrine_config->setAutoGenerateProxyClasses(
				$config->get('laravel-doctrine::doctrine.proxy_classes.auto_generate')
			);

			$doctrine_config->setDefaultRepositoryClassName($config->get('laravel-doctrine::doctrine.defaultRepository'));

			$doctrine_config->setSQLLogger($config->get('laravel-doctrine::doctrine.sqlLogger'));

			$proxy_class_namespace = $config->get('laravel-doctrine::doctrine.proxy_classes.namespace');
			if ($proxy_class_namespace !== null) {
				$doctrine_config->setProxyNamespace($proxy_class_namespace);
			}

			// Trap doctrine events, to support entity table prefix
			$evm = new EventManager();

			if (isset($connection['prefix']) && !empty($connection['prefix'])) {
				$evm->addEventListener(Events::loadClassMetadata, new Listener\Metadata\TablePrefix($connection['prefix']));
			}
                        
			// Obtain an EntityManager from Doctrine.
			return EntityManager::create($connection, $doctrine_config, $evm);

		});

		$this->app->singleton('Doctrine\ORM\Tools\SchemaTool', function ($app) {
			return new SchemaTool($app['Doctrine\ORM\EntityManager']);
		});


		//
		// Utilities
		//

		$this->app->singleton('Doctrine\ORM\Mapping\ClassMetadataFactory', function ($app) {
			return $app['Doctrine\ORM\EntityManager']->getMetadataFactory();
		});

		$this->app->singleton('doctrine.registry', function ($app) {
			$connections = array('doctrine.connection');
			$managers = array('doctrine' => 'doctrine');
			$proxy = 'Doctrine\Common\Persistence\Proxy';
			return new DoctrineRegistry('doctrine', $connections, $managers, $connections[0], $managers['doctrine'], $proxy);
		});


		//
		// String name re-bindings.
		//

		$this->app->singleton('doctrine', function ($app) {
			return $app['Doctrine\ORM\EntityManager'];
		});

		$this->app->singleton('doctrine.metadata-factory', function ($app) {
			return $app['Doctrine\ORM\Mapping\ClassMetadataFactory'];
		});

		$this->app->singleton('doctrine.metadata', function($app) {
			return $app['doctrine.metadata-factory']->getAllMetadata();
		});

		// After binding EntityManager, the DIC can inject this via the constructor type hint!
		$this->app->singleton('doctrine.schema-tool', function ($app) {
			return $app['Doctrine\ORM\Tools\SchemaTool'];
		});

		// Registering the doctrine connection to the IoC container.
		$this->app->singleton('doctrine.connection', function ($app) {
			return $app['doctrine']->getConnection();
		});


		//
		// Commands
		//
		$this->commands(
			array('Atrauzzi\LaravelDoctrine\Console\CreateSchemaCommand',
				'Atrauzzi\LaravelDoctrine\Console\UpdateSchemaCommand',
				'Atrauzzi\LaravelDoctrine\Console\DropSchemaCommand')
		);

	}

	/**
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
	public function provides() {
		return array(
			'doctrine',
			'Doctrine\ORM\EntityManager',
			'doctrine.metadata-factory',
			'Doctrine\ORM\Mapping\ClassMetadataFactory',
			'doctrine.metadata',
			'doctrine.schema-tool',
			'Doctrine\ORM\Tools\SchemaTool',
			'doctrine.registry'
		);
	}


	/**
	 * @param Repository $config
	 * @param string     $cacheType    default, metadata, query, or result
	 *
	 * @return CacheProvider|null
	 */
	private function getCacheProvider(Repository $config, $cacheType)
	{
		$cache = null;

		$cache_provider = $config->get("laravel-doctrine::doctrine.cache.{$cacheType}_provider");

		if ($cache_provider === null) return $cache;

		$cache_provider_config = $config->get("laravel-doctrine::doctrine.cache.providers.$cache_provider");

		switch($cache_provider) {

			case 'apc':
				if(extension_loaded('apc')) {
					$cache = new ApcCache();
				}
				break;

			case 'xcache':
				if(extension_loaded('xcache')) {
					$cache = new XcacheCache();
				}
				break;

			case 'memcache':
				if(extension_loaded('memcache')) {
					$memcache = new \Memcache();
					$memcache->connect($cache_provider_config['host'], $cache_provider_config['port']);
					$cache = new MemcacheCache();
					$cache->setMemcache($memcache);
				}
				break;

			case 'memcached':
				if(extension_loaded('memcached')) {
					$memcache = new \Memcached();
					$memcache->addServer($cache_provider_config['host'], $cache_provider_config['port']);
					$cache = new MemcachedCache();
					$cache->setMemcached($memcache);
				}
				break;

			case 'couchbase':
				if(extension_loaded('couchbase')) {
					$couchbase = new \Couchbase(
						$cache_provider_config['hosts'],
						$cache_provider_config['user'],
						$cache_provider_config['password'],
						$cache_provider_config['bucket'],
						$cache_provider_config['persistent']
					);
					$cache = new CouchbaseCache();
					$cache->setCouchbase($couchbase);
				}
				break;

			case 'redis':
				if(extension_loaded('redis')) {
					$redis = new \Redis();
					$redis->connect($cache_provider_config['host'], $cache_provider_config['port']);

					if ($cache_provider_config['database']) {
						$redis->select($cache_provider_config['database']);
					}

					$cache = new RedisCache();
					$cache->setRedis($redis);
				}
				break;

			default:
				$cache = new ArrayCache();
				break;
		}

		// optionally set cache namespace
		if (isset($cache_provider_config['namespace'])) {
			if ($cache instanceof CacheProvider) {
				$cache->setNamespace($cache_provider_config['namespace']);
			}
		}

		return $cache;
	}

}
