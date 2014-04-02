# Zap\Injector

[![Build Status](https://travis-ci.org/imarc/zap-injector.png?branch=master)](https://travis-ci.org/imarc/zap-injector)

A simple dependency injection library

The Zap Injector looks to find the middle ground between the simplicity offered by tiny
service locators like Pimple and the real dependency injection libraries like PHP-DI
provide.


## Why would I use this?

* It handles resolving dependencies and their configuration in order.
* It allows for dependencies to only be loaded on demand.
* It allows configuration to only be loaded on demand, and to be configured
  from anywhere.
* If you register under parent classes or interfaces, it decouples modules from
  their dependencies.


##  Usage:

```php
$injector = new \iMarc\Zap\Injector();

// Register a factory under a class or interface name:
$injector->register('Request', function() {
	return new Request();
});

// Or Register a specific instance:
$injector->register(Request::createFromGlobals());

// Or register a class to simply be constructed:
$injector->register('Session');

// Invoke a callable, and Injector will fill in the dependencies:
$returnValue = $injector->invoke(function(Request $req, Session $sess) {
	return array($req, $sess);
});

// Similarly, construct an instance of a class with dependencies:
$instance = $injector->create('some\class');
```

Also, Injector has an `extend` method. Extensions are invoked immediately
after a new instance is constructed from a factory or from a classname:

```php
$injector->extend('Request', function(Request $req) {
	$req->setSomethingImportant(true);
});
```


## Changelog

### 0.x

#### 0.3.0
* Major refactoring and code cleanup.

#### 0.2.0
* Added ->register(), removed ->addFactory(), ->addInstance(), and ->addClass()
* Renamed ->remove() to ->unregister()
* Other refactoring

#### 0.1.1
* Added ->extend() method, similar to Pimple. Callbacks configured with
  ->extend() are called after an instance is created by a factory.

#### 0.1.0
* Initial Release, full of bugs
