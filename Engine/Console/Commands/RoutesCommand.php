<?php

namespace Luxid\Console\Commands;

use Luxid\Console\Command;

class RoutesCommand extends Command
{
    protected string $description = 'List all registered routes';

    public function handle(array $argv): int
    {
        $this->parseArguments($argv);

        $this->line("🛣️  Registered Routes");
        $this->line(str_repeat("─", 80));

        $routes = $this->loadRoutes();

        if (empty($routes)) {
            $this->warning("No routes found");
            $this->line("Create routes in: \033[1;34m/routes/api.php\033[0m");
            return 0;
        }

        $tableRows = [];
        foreach ($routes as $route) {
            $tableRows[] = [
                $route['method'],
                $route['path'],
                $route['handler'],
                $route['middleware'] ?? '-'
            ];
        }

        $this->table(['Method', 'Path', 'Handler', 'Middleware'], $tableRows);

        $this->line("");
        $this->info("Total: " . count($routes) . " route(s)");

        return 0;
    }

    private function loadRoutes(): array
    {
        $routes = [];
        $routesFile = $this->getRoutesPath() . '/api.php';

        if (!file_exists($routesFile)) {
            return $routes;
        }

        $content = file_get_contents($routesFile);

        // Parse routes from file content
        // This is a simplified parser - in real implementation, you'd want to parse the actual router calls
        $pattern = '/\\$router->(get|post|put|patch|delete)\\(\\s*[\'"]([^\'"]+)[\'"]\\s*,\\s*([^)]+)\\)/';

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $routes[] = [
                    'method' => strtoupper($match[1]),
                    'path' => $match[2],
                    'handler' => trim($match[3]),
                    'middleware' => $this->extractMiddleware($match[0])
                ];
            }
        }

        return $routes;
    }

    private function extractMiddleware(string $routeLine): string
    {
        if (strpos($routeLine, 'middleware') !== false) {
            return 'Yes';
        }
        return 'No';
    }
}
