# Changelog

Notable changes to this project goes here

## [v3.2.0] - 14.09.2025

### Added

- Method to list all registered routes.
- Method to canonicalize request paths to the same style as the registered pattern.
- Changelog with previous changes for better clarity and transparency.

### Changed

- **[Potential Breaking]** Empty segments in route patterns (e.g. `//`) will now throw an exception in route registration.
- Replaced use of the ctype extension in parameter name validation (added in `v3.0.0`) with preg_match for improved compatibility across PHP environments.
- The route pattern is now included in lookup results if a route is found.

### Improved

- Improved performance of route lookups by being smarter about PHP’s copy-on-write (CoW) behavior.
- Reduced hash map resolution overhead in lookups improving performance.
- Updated benchmark scripts for easier testing and more realistic results.
- Overhauled exception messages by standardizing their prefix and improving consistency.
- Exception messages for route conflicts are now more user friendly and provide clearer context.
- Composer now always includes the router without autoloading for better startup performance.


## [v3.1.1] - 01.09.2025

### Fixed

- Passing an empty array of HTTP methods now throws an exception in route registration.

## [v3.1.0] - 20.08.2025

### Changed

- **[Potential Breaking]** Route registration now requires a leading forward slash (e.g `/path`), an exception is thrown if omitted.

### Improved

- All exception messages have been rewritten to be more clear, descriptive, and consistent.

### Fixed

- Root pattern is now correctly shown in exceptions when failing to register optional parameter variants.

## [v3.0.2] - 17.08.2025

### Fixed
 
- Prevent undefined behavior when mixing wildcard and optional markers at the end of a parameter (e.g `/:param*?`)

## [v3.0.1] - 15.08.2025

### Improved

- Failure when adding optional parameter variants now throws a more descriptive exception message.

## [v3.0.0] - 10.08.2025

There has been one too many breaking changes for such a simple router. I am most certain this will be the last one. Since it's such a common use case, parameter names are now included in the lookup result.

### Changed

- **[BREAKING]** Lookups now return parameters as an associative array, mapping parameter names to their values.
- **[BREAKING]** Parameter names must now start with a letter or underscore, contain only alphanumeric characters or underscores, and cannot be empty.

### Improved

- Enhanced test suite and documentation.

### Migration

**Before:**
```php
$router->add('GET', '/users/:id', 'handler');
$result = $router->lookup('GET', '/users/123');
// $result['params'] = ['123']
```

**After:**
```php
$router->add('GET', '/users/:id', 'handler');
$result = $router->lookup('GET', '/users/123');
// $result['params'] = ['id' => '123']
```

## [v2.1.3] - 08.08.2025

### Improved

- Exception messages have been rewritten to be more clear and descriptive.

### Fixed 

- Root pattern is now correctly shown in exception messages.

## [v2.1.2] - 05.08.2025

### Improved

- Use namespace imports for built-in PHP functions for better performance.

## [v2.1.1] - 02.08.2025

### Improved 

- Enhanced and expanded the test suite for better coverage.
- Updated and clarified documentation for easier understanding and usage.

## [v2.1.0] - 17.07.2025

This is a re-release of v2.0.0 which was quickly removed due to incorrect wildcard fallback behavior.

### Added

- Ability to chain optional parameters (e.g `/:param?/:param?`).
- Wildcard fallback for overlapping dynamic routes.

### Changed

- **[BREAKING]** Paths are now normalized by removing trailing slashes, so routes like `/user` and `/user/` are treated as identical.

### Fixed

- Wildcard fallback behavior of overlapping dynamic segments in v2.0.0.

## [v1.2.1] - 12.07.2025

### Changed

- Lowered the minimum required PHP version from 8.3 to 8.0 (running tests still requires PHP 8.3).

### Improved

- Exception messages for invalid HTTP methods now include the route pattern, providing more user friendly feedback.

## [v1.2.0] - 12.07.2025

Earlier versions were for development purposes. This release consolidates all previous changes and fixes into a single, stable version. All prior releases have been removed. Please use this release for the latest version of the router.
