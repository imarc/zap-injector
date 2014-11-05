<?php
namespace Dummies;

use PHPUnit_Framework_TestCase;

use iMarc\Zap\Injector;

class InjectorTest extends PHPUnit_Framework_TestCase
{
	public function testSetValidOffset()
	{
		$injector = new Injector();

		$injector->register('Closure', function() {
			return new Injector();
		});

		$this->assertInstanceOf('iMarc\Zap\Injector', $injector->get('Closure'));
	}


	/**
     * @expectedException \InvalidArgumentException
	 */
	public function testInvalidClass()
	{
		$injector = new Injector();
		$injector->get('Invalid');
	}


	public function testHas()
	{
		$injector = new Injector();

		// test class name string
		$injector->register('Test', function() {});
		$this->assertEquals(true, $injector->has('Test'));
		
		// test object
		$injector->register('Dummies\DummyClass');
		$dummy = new DummyClass(function(){});
		$this->assertEquals(true, $injector->has($dummy));
	}


	public function testUnregister()
	{
		$injector = new Injector();
		$injector->register('Test', function() {});

		$injector->unregister('Test');

		$this->assertEquals(false, $injector->has('Test'));
	}


	public function testInvoke()
	{
		$test = $this;

		$injector = new Injector();
		$injector->register('Closure', function () { return function() {}; });

		$injector->invoke(
			function(\Closure $func) use ($test) {
				$test->assertInstanceOf('Closure', $func);
			}
		);

		$dummyClass = new DummyClass(function(){});

		$this->assertInstanceOf('Closure', $injector->invoke(array($dummyClass, 'method')));
		$this->assertInstanceOf('Closure', $injector->invoke('globalFunction'));
		$this->assertInstanceOf('Closure', $injector->invoke('Dummies\DummyClass::staticMethod'));
		$this->assertInstanceOf('Closure', $injector->invoke(array('Dummies\DummyClass', 'staticMethod')));
	}

	public function testCreate()
	{
		$injector = new Injector();
		$injector->register('Closure', function () { return function() {}; });

		$this->assertInstanceOf('Dummies\DummyClass', $injector->create('Dummies\DummyClass'));
	}

	public function testAddInstance()
	{
		$test = $this;

		$injector = new Injector();
		$injector->register(function () { return function() {}; });

		$injector->invoke(
			function(\Closure $func) use ($test) {
				$test->assertInstanceOf('Closure', $func);
			}
		);
	}

	public function testAddClass()
	{
		$injector = new Injector();
		$injector->register('Dummies\DummyClass');

		$injector->register(function () { return function() {}; });

		$injector->invoke(function(DummyClass $dummy) {
			$this->assertInstanceOf('Dummies\DummyClass', $dummy);
		});
	}

	/**
     * @expectedException \LogicException
	 */
	public function testInvalidDependency()
	{
		$injector = new Injector();

		$injector->register('Closure', function() {
			return new Injector();
		});

		$injector->register('iMarc\Zap\Injector', function(Injector $injector) {});

		$injector->invoke(function(Injector $injector) {});
	}

	public function testExtend()
	{
		$injector = new Injector();

		$injector->register($injector);

		$injector->register('Closure', function () { return function() {}; });

		$injector->extend('Closure', function(Injector $injector) {
			$injector->register('junk', function() { return 'stuff'; });
		});

		$injector->get('Closure');

		$this->assertEquals('stuff', $injector->get('junk'));
	}
}
