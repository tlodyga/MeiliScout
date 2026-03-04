<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Providers;

use Pollora\MeiliScout\Foundation\ServiceProvider;
use Pollora\MeiliScout\Foundation\Webpack;

use function add_action;
use function is_admin;

/**
 * Service provider for managing and enqueueing plugin admin assets.
 */
class AssetsServiceProvider extends ServiceProvider
{
    /**
     * The Webpack instance for asset handling.
     */
    protected Webpack $webpack;

    /**
     * Creates a new AssetsServiceProvider instance.
     */
    public function __construct()
    {
        $this->webpack = new Webpack;
    }

    /**
     * Registers the service provider's hooks and actions.
     */
    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    /**
     * Vérifie si nous sommes sur une page d'administration MeiliScout.
     */
    private function isMeiliScoutAdminPage(): bool
    {
        if (! is_admin() || ! isset($_GET['page'])) {
            return false;
        }

        $meiliscout_pages = [
            'meiliscout-settings',
            'meiliscout-content-selection',
            'meiliscout-indexation',
        ];

        return in_array($_GET['page'], $meiliscout_pages);
    }

    /**
     * Enqueues the plugin's admin assets using Webpack.
     */
    public function enqueueAssets(): void
    {
        // Enqueue Tailwind CSS and other assets only on MeiliScout admin pages
        if ($this->isMeiliScoutAdminPage()) {
            $this->webpack->enqueueAssets('main', 'main');
        }
    }
}
