<?php
/*
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace iMarc\Zap;

use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;

/**
 * Zap\Injector, a dependency injector.
 *
 * Zap's Injector uses type casting and reflection to determine
 * which dependencies need to be injected into the
 * specified callable;
 *
 * Usage:
 *
 * $injector = new Zap\Injector();
 *
 * $injector->addFactory('Request', function() {
 *     return new Request();
 * });
 *
 * $injector->addInstance('Session', new Session());
 *
 * $injector->addClass('Response');
 *
 * $value = $injector->invoke(function(Request $req, Session $sess) {
 *     return array($req, $sess);
 * })
 *
 * $response = $injector->create('Response');
 *
 */
class Injector
{
	/**
	 * Reflect a callable
	 *
	 * @param $callable Callable
	 *     The callable to reflect
	 *
	 * @return ReflectionFunction|ReflectionMethod
	 */
	static protected function reflectCallable($callable)
	{
		if (is_string($callable) && strpos($callable, '::')) {
			$callable = explode('::', $callable, 2);
		}

		if (is_a($callable, 'Closure')) {
			$reflection = new ReflectionFunction($callable);
		} else if (is_object($callable)) {
			$reflection = new ReflectionMethod(get_class($callable), '__invoke');
		} else if (is_array($callable) && count($callable) == 2) {
			$reflection = new ReflectionMethod((is_object($callable[0]) ? get_class($callable[0]) : $callable[0]), $callable[1]);
		} else if (is_string($callable) && function_exists($callable)) {
			$reflection = new ReflectionFunction($callable);
		}

		return $reflection;
	}


	protected $factories = [];
	protected $instances = [];
	protected $resolving = [];

	/**
	 * Invoke a callable and injects dependencies
	 *
	 * @param $callable mixed
	 *     The Closure or object to inject dependencies into
	 *
	 * @return mixed
	 *     The value return from the callable
	 */
	public function invoke(Callable $callable)
	{
		$args = $this->reflectParameters($callable);

		return call_user_func_array($callable, $args);
	}

	/**
	 * Creates a new instance of a class, injecting dependencies.
	 *
	 * @param $class mixed
	 *     The classname or try to reflect and construct.
	 * @return mixed
	 *     An instance of the class requested.
	 */
	public function create($class)
	{
		if (!$class || !is_string($class)) {
			throw new InvalidArgumentException(sprintf(
				"'%s' is not a valid argument for Injector->create().",
				$class
			));
		}

		try {
			$args = $this->reflectParameters([$class, '__construct']);
		} catch (ReflectionException $e) {
			//Happens when there's no defined constructor
			return new $class();
		}

		$reflection_class = new ReflectionClass($class);
		return $reflection_class->newInstanceArgs($args);
	}

	/**
	 * reflectParameters is an internal
	 *
	 * @param $callable mixed
	 *     The Closure or object to reflect parameters for.
	 * @return mixed[]
	 *     An array of arguments for injection.
	 */
	protected function reflectParameters($callable)
	{
		$reflection = static::reflectCallable($callable);

		$args = [];

		foreach ($reflection->getParameters() as $param) {
			$typehint = $param->getClass();
			if ($typehint === null) {
				if ($param->isDefaultValueAvailable()) {
					$args[] = $param->getDefaultValue();
					continue;
				} else {
					throw new LogicException(sprintf(
						"Argument '%s' is not typehinted and has not default value.",
						$param->getName()
					));
				}
			}

			$typehint =  $typehint->getName();

			if (in_array($typehint, $this->resolving)) {
				throw new LogicException(sprintf(
					"Recursive dependency: '%s' is currently instantiating.",
					$typehint
				));
			}

			$arg = $this->get($typehint);
			if ($arg === null && $param->isDefaultValueAvailable()) {
				$args[] = $param->getDefaultValue();
			} else {
				$args[] = $arg;
			}
		}

		return $args;
	}


	/**
	 * Confirms if a class has been set
	 *
	 * @param $class string
	 *     The type to check
	 *
	 * @return boolean
	 */
	public function has($class)
	{
		return isset($this->factories[$class]) || isset($this->instances[$class]);
	}


	/**
	 * Unsets a registered class
	 *
	 * @param $class string
	 *     The class to unset
	 */
	public function remove($class)
	{
		unset($this->factories[$class]);
		unset($this->instances[$class]);
	}


	/**
	 * get a dependency for the supplied class
	 *
	 * @param $type string
	 *     The type to get
	 *
	 * @return mixed
	 *     The dependency/type value
	 */
	public function get($class)
	{
		var_dump(__METHOD__, $class);
		if (isset($this->instances[$class])) {
			return $this->instances[$class];
		}

		if (isset($this->factories[$class])) {
			array_push($this->resolving, $class);
			$object = $this->invoke($this->factories[$class]);
			array_pop($this->resolving);

			return $object;
		}

		throw new InvalidArgumentException("$class has not been defined");
	}


	/**
	 * Registers a dependency for injection
	 *
	 * @throws \InvalidArgumentException
	 *
	 * @param $class string
	 *     The class to register
	 *
	 * @param $factory mixed A callable
	 *     The factory used to create the dependency
	 */
	public function addFactory($class, $factory)
	{
		if (is_callable($factory)) {
			$this->factories[$class] = $factory;
		} else {
			throw new InvalidArgumentException("Dependency supplied is not callable.");
		}
	}

	public function addInstance($class, $instance=null)
	{
		if ($instance === null) {
			$instance = $class;
			$class = get_class($instance);
		}

		if (is_object($instance)) {
			$this->instances[$class] = $instance;
		} else {
			throw new InvalidArgumentException("Instance is not an object.");
		}
	}

	public function addClass($key_class, $class=null)
	{
		if ($class === null) {
			$class = $key_class;
		}
		if (is_string($class)) {
			$this->factories[$key_class] = function() use ($class) {
				return $this->create($class);
			};
		} else {
			throw new InvalidArgumentException("Classname is not a string.");
		}
	}
}
