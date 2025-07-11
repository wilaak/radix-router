<?php
require 'vendor/autoload.php';

use Piko\Router;

$router = new Router();

// Simulate a real-world set of routes
$routes = [
    ['GET', '/', 'HomeController@index'],
    ['GET', '/login', 'AuthController@showLoginForm'],
    ['POST', '/login', 'AuthController@login'],
    ['POST', '/logout', 'AuthController@logout'],
    ['GET', '/register', 'AuthController@showRegistrationForm'],
    ['POST', '/register', 'AuthController@register'],
    ['GET', '/user/:id', 'UserController@show'],
    ['PUT', '/user/:id', 'UserController@update'],
    ['DELETE', '/user/:id', 'UserController@destroy'],
    ['GET', '/user/:id/posts', 'PostController@userPosts'],
    ['GET', '/user/:id/post/:post', 'PostController@show'],
    ['POST', '/user/:id/post', 'PostController@store'],
    ['PUT', '/user/:id/post/:post', 'PostController@update'],
    ['DELETE', '/user/:id/post/:post', 'PostController@destroy'],
    ['GET', '/admin', 'AdminController@index'],
    ['GET', '/admin/users', 'AdminController@users'],
    ['GET', '/admin/posts', 'AdminController@posts'],
    ['GET', '/search', 'SearchController@index'],
    ['GET', '/about', 'PageController@about'],
    ['GET', '/contact', 'PageController@contact'],
    ['GET', '/settings', 'SettingsController@index'],
    ['POST', '/settings', 'SettingsController@update'],
    ['GET', '/notifications', 'NotificationController@index'],
    ['GET', '/messages', 'MessageController@index'],
    ['GET', '/messages/:id', 'MessageController@show'],
    ['POST', '/messages', 'MessageController@store'],
    ['DELETE', '/messages/:id', 'MessageController@destroy'],
    ['GET', '/profile', 'ProfileController@show'],
    ['POST', '/profile', 'ProfileController@update'],
    ['GET', '/api/v1/users', 'Api\UserController@index'],
    ['GET', '/api/v1/users/:id', 'Api\UserController@show'],
    ['POST', '/api/v1/users', 'Api\UserController@store'],
    ['PUT', '/api/v1/users/:id', 'Api\UserController@update'],
    ['DELETE', '/api/v1/users/:id', 'Api\UserController@destroy'],
    ['GET', '/api/v1/posts', 'Api\PostController@index'],
    ['GET', '/api/v1/posts/:id', 'Api\PostController@show'],
    ['POST', '/api/v1/posts', 'Api\PostController@store'],
    ['PUT', '/api/v1/posts/:id', 'Api\PostController@update'],
    ['DELETE', '/api/v1/posts/:id', 'Api\PostController@destroy'],
    ['GET', '/faq', 'PageController@faq'],
    ['GET', '/terms', 'PageController@terms'],
    ['GET', '/privacy', 'PageController@privacy'],
    ['GET', '/dashboard', 'DashboardController@index'],
    ['GET', '/reports', 'ReportController@index'],
    ['POST', '/reports', 'ReportController@store'],
    ['GET', '/reports/:id', 'ReportController@show'],
    ['PUT', '/reports/:id', 'ReportController@update'],
    ['DELETE', '/reports/:id', 'ReportController@destroy'],
    ['GET', '/files', 'FileController@index'],
    ['POST', '/files', 'FileController@upload'],
    ['GET', '/files/:id', 'FileController@download'],
    ['DELETE', '/files/:id', 'FileController@delete'],
    ['GET', '/events', 'EventController@index'],
    ['POST', '/events', 'EventController@store'],
    ['GET', '/events/:id', 'EventController@show'],
    ['PUT', '/events/:id', 'EventController@update'],
    ['DELETE', '/events/:id', 'EventController@destroy'],
    ['GET', '/calendar', 'CalendarController@index'],
    ['GET', '/calendar/:year/:month', 'CalendarController@month'],
    ['GET', '/tags', 'TagController@index'],
    ['POST', '/tags', 'TagController@store'],
    ['DELETE', '/tags/:id', 'TagController@destroy'],
    ['GET', '/categories', 'CategoryController@index'],
    ['POST', '/categories', 'CategoryController@store'],
    ['DELETE', '/categories/:id', 'CategoryController@destroy'],
    ['GET', '/api/v1/comments', 'Api\CommentController@index'],
    ['POST', '/api/v1/comments', 'Api\CommentController@store'],
    ['DELETE', '/api/v1/comments/:id', 'Api\CommentController@destroy'],
    ['GET', '/api/v1/notifications', 'Api\NotificationController@index'],
    ['POST', '/api/v1/notifications', 'Api\NotificationController@store'],
    ['DELETE', '/api/v1/notifications/:id', 'Api\NotificationController@destroy'],
];

// Pick a set of paths to test (simulate real requests)
$testPaths = [
    ['GET', '/user/123'],
    ['GET', '/user/456/posts'],
    ['GET', '/user/789/post/1011'],
    ['PUT', '/user/222/post/333'],
    ['DELETE', '/user/555/post/666'],
    ['GET', '/messages/42'],
    ['DELETE', '/messages/99'],
    ['GET', '/api/v1/users/77'],
    ['PUT', '/api/v1/users/88'],
    ['DELETE', '/api/v1/users/99'],
    ['GET', '/api/v1/posts/1234'],
    ['PUT', '/api/v1/posts/5678'],
    ['DELETE', '/api/v1/posts/4321'],
    ['GET', '/reports/202'],
    ['PUT', '/reports/303'],
    ['DELETE', '/reports/404'],
    ['GET', '/files/5555'],
    ['DELETE', '/files/6666'],
    ['GET', '/events/777'],
    ['PUT', '/events/888'],
    ['DELETE', '/events/999'],
    ['GET', '/calendar/2024/06'],
    ['GET', '/tags'],
    ['POST', '/tags'],
    ['DELETE', '/tags/123'],
    ['GET', '/categories'],
    ['POST', '/categories'],
    ['DELETE', '/categories/456'],
    ['GET', '/api/v1/comments'],
    ['POST', '/api/v1/comments'],
    ['DELETE', '/api/v1/comments/789'],
    ['GET', '/api/v1/notifications'],
    ['POST', '/api/v1/notifications'],
    ['DELETE', '/api/v1/notifications/321'],
    // A few static for comparison
    ['GET', '/dashboard'],
    ['GET', '/about'],
    ['GET', '/faq'],
    ['GET', '/privacy'],
    ['GET', '/terms'],
];

// Register routes
foreach ($routes as [$method, $path, $handler]) {
    $router->addRoute($path, $handler);
}

$iterations = 2000000;
$start = microtime(true);

for ($i = 0; $i < $iterations; $i++) {
    // Cycle through test paths for each iteration
    $index = $i % count($testPaths);
    [$method, $path] = $testPaths[$index];
    $router->resolve($method, $path);
}


$end = microtime(true);
$duration = $end - $start;
$lookupsPerSecond = $iterations / $duration;

echo "Route lookups per second: " . number_format($lookupsPerSecond, 2) . PHP_EOL;
echo "Total time for $iterations lookups: " . number_format($duration, 6) . " seconds" . PHP_EOL;
echo "Average time per lookup: " . number_format(($duration / $iterations) * 1e6, 4) . " microseconds" . PHP_EOL;
echo "Memory usage: " . number_format(memory_get_usage() / 1024, 2) . " KB" . PHP_EOL;
echo "Peak memory usage: " . number_format(memory_get_peak_usage() / 1024, 2) . " KB" . PHP_EOL;
echo "Registered routes: " . count($routes) . PHP_EOL;
echo "Tested paths: " . count($testPaths) . PHP_EOL;
