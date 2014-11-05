<?php
/*
 * This file is part of the Zap package.
 *
 * (c) iMarc LLC <info@imarc.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace iMarc\Zap;

use Closure;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;

/**
 * A dependency injector.
 *
 * Zap's Injector uses type hinting and reflection to determine
 * which dependencies need to be injected into the specified callable
 * or constructor.
 *
 * Usage:
 *
 * $injector = new Zap\Injector();
 *
 * $injector->register('Request', function() {
 *     return new Request();
 * });
 *
 * $injector->register('Session', new Session());
 *
 * $injector->register('Response');
 *
 * $value = $injector->invoke(function(Request $req, Session $sess) {
 *     return array($req, $sess);
 * })
 *
 * $response = $injector->create('Response');
 *
 * @package Zap
 * @author Jeff Turcotte <jeff@imarc.net>
 * @author Kevin Hamer <kevin@imarc.net>
 * @license MIT (see LICENCE file)
 */
class Injector
{
	protected $factories  = [];
	protected $extensions = [];
	protected $instances  = [];
	protected $resolving  = [];


	/**
	 * Constructor
	 *
	 * @return Injector
	 */
	public function __construct()
	{
		$this->register($this);
	}


	/**
	 * Creates a new instance of a class, injecting dependencies.
	 *
	 * @param string $class
	 *     The classname or try to reflect and construct.
	 *
	 * @return mixed
	 *     An instance of the class requested.
	 */
	public function create($class)
	{
		if (!method_exists($class, '__construct')) {
			return new $class();
		}

		$args = $this->getArguments([$class, '__construct']);

		return (new ReflectionClass($class))->newInstanceArgs($args);
	}


	/**
	 * Extends a dependency with additional functionality or settings
	 *
	 * @param string $class
	 *     The class or interface name
	 *
	 * @param Callable $callable
	 *     The callable to extend the class with
	 *
	 * @return void
	 */
	public function extend($class, Callable $callable)
	{
		if (!isset($this->extensions[$class])) {
			$this->extensions[$class] = [];
		}

		$this->extensions[$class][] = $callable;
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
		if (!$this->has($class)) {
			throw new InvalidArgumentException(sprintf(
				"'%s' has not been defined", $class
			));
		}

		array_push($this->resolving, $class);

		if (!isset($this->instances[$class])) {
			$this->instances[$class] = $this->invoke($this->factories[$class]);
		}

		array_pop($this->resolving);

		while ($extension = array_shift($this->extensions[$class])) {
			$this->invoke($extension);
		}

		return $this->instances[$class];
	}


	/**
	 * Confirms if a class or interface has been defined in the Injector.
	 *
	 * @param mixed $class
	 *     The class, interface, or object to check.
	 *
	 * @return boolean
	 *     Whether it has been defined.
	 */
	public function has($class)
	{
		if (is_object($class)) {
			$class = get_class($class);
		}

		return isset($this->factories[$class]);
	}


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
		$args = $this->getArguments($callable);

		return call_user_func_array($callable, $args);
	}


	/**
	 * Register a dependency. The following formats are allowed:
	 *
	 * 1. Fully qualified class (or interface) name with factory closure
	 *     $injector->register('Fully\Qualified\ClassName', function { .... });
	 *
	 * 2. Fully qualified class (or interface) name with instance of said class
	 *     $injector->register('Fully\Qualified\ClassName', $instance);
	 *
	 * 3. Fully qualified class (or interface) name with factory Callable
	 *     $injector->register('Fully\Qualified\ClassName', [$instance, 'factoryMethod']);
	 *
	 * 4. Fully qualified class name only. Will inject dependencies into the constructor
	 *     $injector->register('Fully\Qualified\ClassName');
	 *
	 * 5. Fully qualified interface name with fully qualified class name
	 *     $injector->register('Fuilly\Qualified\InterfaceName', 'Fully\Qualified\ClassName');
	 *
	 * 6. Instance only, finds class internally with: get_class($instance)
	 *     $injector->register($instance);
	 *
	 * @param mixed $type
	 *     A fully qualified class name, interface name, or object
	 *
	 * @param mixed $implementation
	 *     A Closure factory, Callable factory, instance, or fully qualified class name
	 *
	 * @return iMarc\Zap\Injector
	 *     The injector instance
	 */
	public function register($type, $implementation = null)
	{
		if ($implementation instanceof Closure && is_string($type)) {
			$this->factories[$type] = $implementation;
		} else if (is_object($implementation) && is_string($type)) {
			$this->factories[$type] = $this->createFactory($implementation);
		} else if (is_callable($implementation) && is_string($type)) {
			$this->factories[$type] = $implementation;
		} else if ($implementation === null && is_string($type)) {
			$this->factories[$type] = $this->createFactory($type);
		} else if (is_string($type) && is_string($implementation)) {
			$this->factories[$type] = $this->createFactory($implementation);
		} else if ($implementation === null && is_object($type)) {
			$this->factories[get_class($type)] = $this->createFactory($type);
			$type = get_class($type);
		} else {
			throw new InvalidArgumentException("Invalid dependency registration");
		}

		// initialize extensions
		if (!isset($this->extensions[$type])) {
			$this->extensions[$type] = [];
		}

		return $this;
	}


	/**
	 * Unsets a registered class.
	 *
	 * @param string $class
	 *     The class or interface to unset.
	 *
	 * @return void
	 */
	public function unregister($class)
	{
		unset($this->factories[$class]);
		unset($this->instances[$class]);
		unset($this->extensions[$class]);
	}


	/**
	 * Generate a simple closure factory for a class
	 *
	 * @param mixed $class
	 *     The class name or instance
	 *
	 * @return Closure
	 *     The factory
	 */
	protected function createFactory($class)
	{
		return function() use ($class) {
			return is_object($class) ? $class : $this->create($class);
		};
	}


	/**
	 * Collects and returns all dependency arguments for a Callable
	 *
	 * @param mixed $callable
	 *     The callable (or constructor "callable") to reflect parameters for.
	 *
	 * @return array
	 *     An array of arguments for injection.
	 */
	protected function getArguments($callable)
	{
		// convert Class::method callable to an array
		if (is_string($callable) && strpos($callable, '::')) {
			$callable = explode('::', $callable, 2);
		}

		// get proper reflection class for "callable"
		if (is_a($callable, 'Closure')) {
			$reflection = new ReflectionFunction($callable);
		} else if (is_object($callable)) {
			$reflection = new ReflectionMethod(get_class($callable), '__invoke');
		} else if (is_array($callable) && count($callable) == 2) {
			$reflection = new ReflectionMethod((is_object($callable[0]) ? get_class($callable[0]) : $callable[0]), $callable[1]);
		} else if (is_string($callable) && function_exists($callable)) {
			$reflection = new ReflectionFunction($callable);
		}

		$args = [];

		foreach ($reflection->getParameters() as $param) {
			if ($param->getClass() === null) {
				if ($param->isDefaultValueAvailable()) {
					$args[] = $param->getDefaultValue();
					continue;
				}

				throw new LogicException(sprintf(
					"Argument '%s' is not typehinted and has not default value.",
					$param->getName()
				));
			}

			$class = $param->getClass()->getName();

			if (in_array($class, $this->resolving)) {
				throw new LogicException(sprintf(
					"Recursive dependency: '%s' is currently instantiating.",
					$class
				));
			}

			$args[] = (!$this->has($class) && $param->isDefaultValueAvailable())
				? $param->getDefaultValue()
				: $this->get($class);
		}

		return $args;
	}
}
