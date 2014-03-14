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
	 * @param Callable $callable
	 *     The callable to reflect
	 *
	 * @return ReflectionFunction|ReflectionMethod
	 *     The ReflectionFunction or ReflectionMethod for the given callable.
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
	protected $extensions = [];
	protected $instances = [];
	protected $resolving = [];

	/**
	 * Invokes a callable, injecting dependencies to match its reflected parameters.
	 *
	 * @param Callable $callable
	 *     The Closure or object to inject dependencies into and invoke.
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
	 * @param string $class
	 *     The classname or try to reflect and construct.
	 * @return mixed
	 *     An instance of the class requested.
	 */
	public function create($class)
	{
		if (!$class || !is_string($class)) {
			throw new InvalidArgumentException(sprintf(
				"'%s' is not a valid argument for ->create().",
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
	 * reflectParameters is an internal method that reflects and returns an
	 * array of arguments for injection.
	 *
	 * @param mixed $callable
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
	 * Confirms if a class or interface has been defined in the Injector.
	 *
	 * @param string $class
	 *     The class or interface name to check.
	 *
	 * @return boolean
	 *     Whether it has been defined.
	 */
	public function has($class)
	{
		if (!$class || !is_string($class)) {
			throw new InvalidArgumentException(sprintf(
				"'%s' is not a valid argument for ->has().",
				$class
			));
		}

		return isset($this->factories[$class]) || isset($this->instances[$class]);
	}


	/**
	 * Unsets a registered class.
	 *
	 * @param string $class
	 *     The class or interface to unset.
	 */
	public function remove($class)
	{
		unset($this->factories[$class]);
		unset($this->instances[$class]);
	}


	/**
	 * Get a dependency from the Injector for class or interface name.
	 *
	 * @param string $class
	 *     The $class or interface name to get.
	 *
	 * @return mixed
	 *     The dependency/type value
	 */
	public function get($class)
	{
		if (!$class || !is_string($class)) {
			throw new InvalidArgumentException(sprintf(
				"'%s' is not a valid argument for ->get().",
				$class
			));
		}

		if (isset($this->instances[$class])) {
			$instance = $this->instances[$class];

		} elseif (isset($this->factories[$class])) {
			array_push($this->resolving, $class);
			$instance = $this->invoke($this->factories[$class]);
			array_pop($this->resolving);

		} else {
			throw new InvalidArgumentException(sprintf(
				"'%s' has not been defined",
				$class
			));
		}

		if (isset($this->extensions[$class])) {
			foreach ($this->extensions[$class] as $extension) {
				$this->invoke($extension);
			}
		}

		return $instance;
	}


	/**
	 * Registers a factory for dependency injection. This factory will only be
	 * called the first time the dependency is needed; after that, the instance
	 * is stored and reused.
	 *
	 * @throws \InvalidArgumentException
	 *
	 * @param string $class
	 *     The class or interface to register this factory for.
	 *
	 * @param Callable $factory
	 *     The factory used to create the dependency.
	 */
	public function addFactory($class, Callable $factory)
	{
		if (is_callable($factory)) {
			$this->factories[$class] = $factory;
		} else {
			throw new InvalidArgumentException("Dependency supplied is not callable.");
		}
	}

	/**
	 * Registers an instance for dependency injection. If only one argument is
	 * provided, that argument is used as the instance and the classname of the
	 * instance is used for $class.
	 *
	 * @param string $class
	 *     The class or interface to register this instance for.
	 * @param mixed $instance
	 *     The instance to register.
	 */
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

	/**
	 * Registers a class for dependency injection. If only one argument is
	 * provided, that argument is used as both the classname to construct and
	 * the class to register the class for.
	 *
	 * @param mixed $key_class
	 *     The class or interface to register this instance for.
	 * @param mixed $class
	 *     The class to construct. Dependencies will be injected to the
	 *     constructor.
	 */
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

	public function extend($class, Callable $callable)
	{
		if (!isset($this->extensions[$class])) {
			$this->extensions[$class] = [];
		}
		$this->extensions[$class][] = $callable;
	}
}
