<?php
// Engine/Console/Commands/MigrateCommand.php
namespace Luxid\Console\Commands;

use Luxid\Console\Command;
use Luxid\Foundation\Application;

class MigrateCommand extends Command
{
    protected string $description = 'Run database migrations';

    public function handle(array $argv): int
    {
        $this->parseArguments($argv);

        $fresh = $this->options['fresh'] ?? false;

        if ($fresh) {
            return $this->freshMigrate();
        }

        return $this->runMigrations();
    }

    private function freshMigrate(): int
    {
        $this->line("🧹 Fresh migration: dropping all tables and re-running migrations");

        if (!$this->confirm("This will drop all tables! Are you sure?", false)) {
            $this->line("Operation cancelled");
            return 0;
        }

        // Setup application first
        $this->setupApplication();
        $db = Application::$app->db;

        // Get all tables
        try {
            $stmt = $db->pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            if (!empty($tables)) {
                $this->line("Dropping tables...");
                $db->pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

                foreach ($tables as $table) {
                    $this->line("  Dropping: {$table}");
                    $db->pdo->exec("DROP TABLE IF EXISTS `{$table}`");
                }

                $db->pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                $this->info("All tables dropped");
            }
        } catch (\Exception $e) {
            $this->error("Failed to drop tables: " . $e->getMessage());
            return 1;
        }

        // Now run migrations
        return $this->runMigrations();
    }

    private function runMigrations(): int
    {
        $this->line("🔄 Running migrations...");

        // Setup application
        $this->setupApplication();

        // Ensure migrations table exists
        $this->ensureMigrationsTable();

        // Get migrations directory
        $migrationsPath = $this->getMigrationsPath();
        if (!is_dir($migrationsPath)) {
            $this->warning("No migrations directory found");
            $this->line("Create your first migration: \033[1;32mphp juice make:migration create_users_table\033[0m");
            return 0;
        }

        // Get all migration files
        $files = scandir($migrationsPath);
        $migrationFiles = array_filter($files, function($file) {
            return $file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'php';
        });

        if (empty($migrationFiles)) {
            $this->warning("No migration files found");
            $this->line("Create migrations: \033[1;32mphp juice make:migration <name>\033[0m");
            return 0;
        }

        sort($migrationFiles);

        // Get already applied migrations
        $appliedMigrations = $this->getAppliedMigrations();

        $applied = 0;
        $db = Application::$app->db;

        foreach ($migrationFiles as $migrationFile) {
            $migrationId = pathinfo($migrationFile, PATHINFO_FILENAME);

            // Skip if already applied
            if (in_array($migrationId, $appliedMigrations)) {
                $this->line("  \033[90mSkipping: {$migrationId} (already applied)\033[0m");
                continue;
            }

            $this->line("  \033[33mApplying:\033[0m {$migrationFile}");

            try {
                require_once $migrationsPath . '/' . $migrationFile;

                if (!class_exists($migrationId)) {
                    $this->warning("    Class {$migrationId} not found in file");
                    continue;
                }

                $instance = new $migrationId();
                if (method_exists($instance, 'apply')) {
                    // Start transaction
                    $db->pdo->beginTransaction();

                    try {
                        $instance->apply();

                        // Record migration
                        $stmt = $db->pdo->prepare("INSERT INTO migrations (migration_id, applied_at) VALUES (?, NOW())");
                        $stmt->execute([$migrationId]);

                        $db->pdo->commit();
                        $this->line("    \033[32m✓ Applied\033[0m");
                        $applied++;
                    } catch (\Exception $e) {
                        $db->pdo->rollBack();
                        throw $e;
                    }
                } else {
                    $this->warning("    No apply() method found");
                }
            } catch (\Exception $e) {
                $this->error("    Failed: " . $e->getMessage());
                $this->line("    \033[31mRolling back...\033[0m");
                return 1;
            }
        }

        if ($applied > 0) {
            $this->success("{$applied} migration(s) applied successfully!");
        } else {
            $this->line("✅ All migrations are already applied");
        }

        return 0;
    }

    private function setupApplication(): void
    {
        // Load environment
        $rootPath = $this->getProjectRoot();
        $envFile = $rootPath . '/.env';

        if (file_exists($envFile)) {
            $dotenv = \Dotenv\Dotenv::createImmutable($rootPath);
            $dotenv->load();
        }

        // Create config
        $config = [
            'db' => [
                'dsn' => $_ENV['DB_DSN'] ?? '',
                'user' => $_ENV['DB_USER'] ?? '',
                'password' => $_ENV['DB_PASSWORD'] ?? '',
            ],
        ];

        // Create application instance if not already created
        if (!isset(Application::$app)) {
            new Application($rootPath, $config);
        }
    }

    private function ensureMigrationsTable(): void
    {
        $db = Application::$app->db;

        $sql = "CREATE TABLE IF NOT EXISTS migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration_id VARCHAR(255) NOT NULL UNIQUE,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_migration_id (migration_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $db->pdo->exec($sql);
    }

    private function getAppliedMigrations(): array
    {
        $db = Application::$app->db;

        try {
            $stmt = $db->pdo->query("SELECT migration_id FROM migrations ORDER BY applied_at");
            return $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);
        } catch (\Exception $e) {
            // Table might not exist yet
            return [];
        }
    }
}
