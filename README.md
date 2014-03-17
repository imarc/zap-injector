# Zap\Injector

A simple dependency injection library

The Zap Injector looks to find the middle ground between the simplicity offered by tiny
service locators like Pimple and the real dependency injection libraries like PHP-DI
provide.

##  Usage:

```(php)
$injector = new \iMarc\Zap\Injector();

// configure your shared dependencies

$injector->addFactory('Request', function() {
	return new Request();
});

$injector->addInstance(Request::createFromGlobals());

$injector->addClass('Session');

// invoke a callable with the dependencies

$returnValue = $injector->invoke(function(Request $req, Session $sess) {
	return array($req, $sess);
});

// call a constructor with dependencies

$instance = $injector->create('some\class');
```

## Changelog

### 1.x

#### 1.1.0
* Added ->extend() method, similar to Pimple. Callbacks configured with
  ->extend() are called after an instance is created by a factory.

#### 1.0.0
* Initial Release, full of bugs
