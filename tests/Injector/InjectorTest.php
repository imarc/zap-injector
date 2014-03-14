<?php
namespace Dummies;

use PHPUnit_Framework_TestCase;

use iMarc\Zap\Injector;

class InjectorTest extends PHPUnit_Framework_TestCase
{
	public function testSetValidOffset()
	{
		$injector = new Injector();

		$injector->addFactory('Closure', function() {
			return new Injector();
		});

		$injector->addFactory('CallableObject', new DummyFactory());

		$this->assertInstanceOf('iMarc\Zap\Injector', $injector->get('Closure'));
		$this->assertInstanceOf('iMarc\Zap\Injector', $injector->get('CallableObject'));
	}


	/**
     * @expectedException \InvalidArgumentException
	 */
	public function testGetInvalidOffset()
	{
		$injector = new Injector();
		$injector->get('Invalid');
	}


	public function testIsset()
	{
		$injector = new Injector();
		$injector->addFactory('Test', function() {});

		$this->assertEquals(true, $injector->has('Test'));
	}


	public function testUnset()
	{
		$injector = new Injector();
		$injector->addFactory('Test', function() {});

		$injector->remove('Test');

		$this->assertEquals(false, $injector->has('Test'));
	}


	public function testInvoke()
	{
		$test = $this;

		$injector = new Injector();
		$injector->addFactory('iMarc\Zap\Injector', new DummyFactory());
		$injector->addFactory('Closure', function () { return function() {}; });

		$injector->invoke(
			function(\Closure $func, Injector $injector) use ($test) {
				$test->assertInstanceOf('Closure', $func);
				$test->assertInstanceOf('iMarc\Zap\Injector', $injector);
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
		$injector->addFactory('Closure', function () { return function() {}; });

		$this->assertInstanceOf('Dummies\DummyClass', $injector->create('Dummies\DummyClass'));
	}

	public function testAddInstance()
	{
		$test = $this;

		$injector = new Injector();
		$injector->addInstance(function () { return function() {}; });

		$injector->invoke(
			function(\Closure $func) use ($test) {
				$test->assertInstanceOf('Closure', $func);
			}
		);
	}

	public function testAddClass()
	{
		$injector = new Injector();
		$injector->addClass('Dummies\DummyClass');

		$injector->addInstance(function () { return function() {}; });

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

		$injector->addFactory('Closure', function() {
			return new Injector();
		});

		$injector->addFactory('iMarc\Zap\Injector', function(Injector $injector) {});

		$injector->invoke(function(Injector $injector) {});
	}

	public function testExtend()
	{
		$injector = new Injector();

		$injector->addInstance($injector);

		$injector->addFactory('Closure', function () { return function() {}; });

		$injector->extend('Closure', function(Injector $injector) {
			$injector->addFactory('junk', function() { return 'stuff'; });
		});

		$injector->get('Closure');

		$this->assertEquals('stuff', $injector->get('junk'));
	}
}
