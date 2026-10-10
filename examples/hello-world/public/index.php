<?php

require __DIR__ . '/../vendor/autoload.php';

use Wilaak\Http\RadixRouter;

$router = new RadixRouter()
    ->add('GET', '/:name?', function ($name = 'World') {
        echo "Hello, {$name}!";
    });

$result = $router->lookup(
    $_SERVER['REQUEST_METHOD'],
    rawurldecode(strtok($_SERVER['REQUEST_URI'], '?')),
);

switch ($result['code']) {
    case 200:
        $result['handler'](...$result['params']);
        break;

    case 404:
        http_response_code(404);
        echo '404 Not Found';
        break;
        
    case 405:
        header('Allow: ' . implode(',', $result['allowed_methods']));
        http_response_code(405);
        echo '405 Method Not Allowed';
        break;
}