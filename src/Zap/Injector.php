<?php
/*
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zap;

use InvalidArgumentException;
use LogicException;

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
			$reflection = new \ReflectionFunction($callable);
		} else if (is_object($callable)) {
			$reflection = new \ReflectionMethod(get_class($callable), '__invoke');
		} else if (is_array($callable) && count($callable) == 2) {
			$reflection = new \ReflectionMethod((is_object($callable[0]) ? get_class($callable[0]) : $callable[0]), $callable[1]);
		} else if (is_string($callable) && function_exists($callable)) {
			$reflection = new \ReflectionFunction($callable);
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
		$reflection = static::reflectCallable($callable);

		$args = [];

		foreach($reflection->getParameters() as $param) {
			$type = $param->getClass()->getName();

			if (in_array($type, $this->resolving)) {
				throw new LogicException("Recursive dependency: $type is currently instatiating.");
			}

			$arg = $this->get($type);
			$args[] = $param->allowsNull() && $arg === undefined ? null : $arg;
		}

		return call_user_func_array($callable, $args);
	}

	public function create($class)
	{
		$reflection = static::reflectCallable([$class, '__construct']);

		$args = [];

		foreach($reflection->getParameters() as $param) {
			$type = $param->getClass()->getName();

			if (in_array($type, $this->resolving)) {
				throw new LogicException("Recursive dependency: $type is currently instatiating.");
			}

			$arg = $this->get($type);
			$args[] = $param->allowsNull() && $arg === undefined ? null : $arg;
		}

		$reflection_class = $reflection->getDeclaringClass();
		return $reflection_class->newInstanceArgs($args);
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

	public function addInstance($instance)
	{
		if (is_object($instance)) {
			$class = get_class($instance);
			$this->instances[$class] = $instance;
		} else {
			throw new InvalidArgumentException("Instance is not an object.");
		}
	}

	public function addClass($class)
	{
		if (is_string($class)) {
			$this->factories[$class] = function() use ($class) {
				return $this->create($class);
			};
		} else {
			throw new InvalidArgumentException("Classname is not a string.");
		}
	}
}
