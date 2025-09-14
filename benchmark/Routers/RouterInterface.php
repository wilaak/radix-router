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
     * @return array Adapted routes
     */
    public function adapt(array $routes): array;

    /**
     * Register routes.
     */
    public function register(array $adaptedRoutes): void;

    /**
     * Lookup a route.
     */
    public function lookup(string $path): void;

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