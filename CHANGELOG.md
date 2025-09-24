# Changelog

Notable changes to this project goes here

## [v3.4.2] - 24.09.2025

### Changed

- Exception messages no longer end with periods, following PHP conventions.
- Adjusted baseline memory usage in benchmarks.
- Updated registration time benchmark for more realistic results.

### Removed

- Path correction section in README


## [v3.4.1] - 18.09.2025

### Fixed

- Fixed bug causing incorrect prioritization when mixing wildcard parameters under certain conditions. ([#2](https://github.com/Wilaak/RadixRouter/issues/2))

## [v3.4.0] - 18.09.2025

This release provides an important bug fix for users running v3.2.0 and v3.3.0. This could cause valid routes to be missed and result in incorrect 404 responses. If your application uses parameters that may be zero, upgrading is strongly recommended.

### Added

- Support for required wildcard parameters (e.g. `/assets/:resource+`).
- Path traversal warnings for wildcard examples in README.
- Examples for using required wildcard parameters in README.

### Improved

- Expanded test suite coverage for edge cases and advanced route patterns.

### Fixed

- Fixed an issue where required parameters failed to match when the value was 0 (introduced v3.2.0). 
- Corrected a typo in the exception message for route conflicts.


## [v3.3.0] - 16.09.2025

### Added

- Method to list allowed HTTP methods for a specific path.
- Section for extending built-in HTTP methods in README.

### Changed

- Updated benchmarking scripts for more realistic results and added options for convenience.
- Section for handling OPTIONS requests now references the new method in README.

### Fixed

- Unsafe redirect example in path correction section is now removed and clarified.

## [v3.2.0] - 15.09.2025

### Added

- Method to list all routes or routes for a specific path.
- Changelog with previous changes for better clarity and transparency.
- Examples for path correction and OPTIONS method handling in README.

### Changed

- **[Potential Breaking]** Empty segments in route patterns (e.g. `//`) will now throw an exception.
- Improved compatibility: Replaced `ctype` extension (introduced v3.0.0) with `preg_match`.
- Route pattern is now included in lookup results.
- Overhauled exception messages; standardizing their prefix and improving consistency.
- Route conflict exceptions now show both the original conflicting patterns.
- Updated benchmark scripts for more realistic results and easier usage.
- Composer now includes the router by default, improving startup performance.

### Improved

- Improved lookup performance by optimizing copy-on-write and hash map usage.

## [v3.1.1] - 01.09.2025

### Fixed

- Passing an empty array of HTTP methods now throws an exception in route registration.

## [v3.1.0] - 20.08.2025

### Changed

- **[Potential Breaking]** Route patterns must now start with a forward slash.

### Improved

- All exception messages have been rewritten to be more clear, descriptive, and consistent.

### Fixed

- Exceptions now correctly show the root pattern for optional parameters.

## [v3.0.2] - 17.08.2025

### Fixed
 
- Prevent undefined behaviors when combining wildcard and optional parameter markers.

## [v3.0.1] - 15.08.2025

### Improved

- Route conflict exceptions now clarify when a pattern is optional, providing better context for debugging.

## [v3.0.0] - 10.08.2025

There has been one too many breaking changes for such a simple router. I am most certain this will be the last one. Since it's such a common use case, parameter names are now included in the lookup result.

### Changed

- **[BREAKING]** Lookups now return parameters as a named array. Parameter names must start with a letter or underscore and only contain alphanumeric characters or underscores.

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

- Ability to chain optional parameters (e.g. `/:param?/:param?`).
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
