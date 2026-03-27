<?php

namespace Wilaak\Http\RadixRouter\Benchmark\Routers;

interface RouterInterface
{
    /**
     * Initialize the router instance.
     * 
     * @param string $tmpFile Temporary file path for caching if needed.
     */
    public function mount(string $tmpFile): void;

    /**
     * Adapt routes to the router's expected format.
     *
     * E.g., converting {param} to :param for RadixRouter.
     *
     * Example route formats:
     * - Static: /about, /contact
     * - Dynamic: /user/{id}, /post/{slug}
     *
     * @param array $routes Array of [method, path] pairs
     * @return array Array of [method, adapted_path] pairs
     */
    public function adapt(array $routes): array;

    /**
     * Register routes.
     *
     * @param array $adaptedRoutes Array of [method, adapted_path] pairs
     */
    public function register(array $adaptedRoutes): void;

    /**
     * Lookup a route.
     *
     * @param string $method HTTP method (e.g. GET, POST)
     * @param string $path URL path to look up
     */
    public function lookup(string $method, string $path): void;

    /**
     * Get router details.
     *
     * @return array{
     *     name: string,
     *     description: string,
     * }
     */
    public static function details(): array;
}