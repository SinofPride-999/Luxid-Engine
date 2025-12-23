<?php

namespace Luxid\Console\Commands;

use Luxid\Console\Command;

class VersionCommand extends Command
{
    protected string $description = 'Show Luxid version';

    public function handle(array $argv): int
    {
        $this->parseArguments($argv);

        $engineVersion = '0.1.0';
        $juiceVersion = '1.0.0';

        $this->line("🍋 \033[1;36mJuice CLI v{$juiceVersion}\033[0m");
        $this->line("📦 \033[1;33mLuxid Engine v{$engineVersion}\033[0m");
        $this->line("🐘 \033[1;35mPHP " . PHP_VERSION . "\033[0m");
        $this->line("⚡ \033[1;32m" . php_uname('s') . " " . php_uname('r') . "\033[0m");

        return 0;
    }
}
