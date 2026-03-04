<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Providers;

use Pollora\MeiliScout\Commands\IndexCommand;
use Pollora\MeiliScout\Foundation\ServiceProvider;

/**
 * Service provider for registering WP-CLI commands.
 */
class CommandServiceProvider extends ServiceProvider
{
    /**
     * Registers WP-CLI commands when in CLI environment.
     */
    public function register(): void
    {
        if (defined('WP_CLI') && WP_CLI) {
            $indexCommand = new IndexCommand;
            \WP_CLI::add_command('meiliscout index', $indexCommand);
            \WP_CLI::add_command('meiliscout index-chunk', [$indexCommand, 'index_chunk']);
        }
    }
}
