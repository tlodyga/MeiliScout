# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

### Admin Assets Development
```bash
# Start development server with hot reload
npm run start

# Build for production
npm run build

# Linting and formatting
npm run lint:js
npm run lint:css
npm run format
```

### PHP Development
```bash
# Run PHP tests with PestPHP
vendor/bin/pest

# Run specific test file
vendor/bin/pest tests/Unit/Indexables/Post/QueryBuilder/BasicQueryTest.php

# Run PHPUnit (alternative)
vendor/bin/phpunit

# Run PHPStan static analysis
ddev exec --dir /var/www/html/public/content/plugins/meiliscout composer test:types

# Run all tests (lint + types + unit)
composer test
```

### DDEV Environment
When working in the DDEV environment, prefix commands with:
```bash
ddev exec --dir /var/www/html/public/content/plugins/meiliscout [command]
```

Example:
```bash
ddev exec --dir /var/www/html/public/content/plugins/meiliscout composer test:types
```

### Build System
- Uses WordPress Scripts (@wordpress/scripts) for modern build pipeline
- Webpack configuration extends WordPress defaults for admin assets
- TailwindCSS integration via PostCSS for admin UI

## Architecture Overview

### Core Framework
**MeiliScout** is a modern WordPress plugin that integrates Meilisearch with a sophisticated, modular architecture:

- **Service Container**: Custom PSR-11 compliant dependency injection container
- **Service Provider Pattern**: Modular service registration system in `src/Providers/`
- **Domain-Driven Design**: Business logic organized in `src/Domain/`
- **Query Builder Pattern**: Fluent interface for building Meilisearch queries

### Key Components

#### Foundation Layer (`src/Foundation/`)
- `Application.php`: Main bootstrapper that registers all service providers
- `Container.php`: PSR-11 dependency injection container with singleton support
- Entry point bootstraps through service providers defined in `Application::$providers`

#### Query System (`src/Query/`)
- `MeiliQueryBuilder`: Main query builder with specialized sub-builders
- `QueryIntegration`: Integrates with WordPress query system
- `WPQueryAdapter`: Adapts WordPress queries to Meilisearch format
- Multiple specialized builders: `MetaQueryBuilder`, `TaxQueryBuilder`, `SearchQueryBuilder`, etc.

#### Indexables (`src/Indexables/`)
- `PostIndexable`: WordPress post indexing implementation
- `TaxonomyIndexable`: Taxonomy indexing implementation
- Implements `Indexable` contract for extensibility

#### Services (`src/Services/`)
- `Indexer`: Main indexing service with bulk and chunked operations
- `PostSingleIndexer`: Real-time single post indexing
- `TaxonomySingleIndexer`: Real-time single taxonomy indexing
- `AsyncIndexingQueue`: WP-Cron based async indexing queue (opt-in)
- `IndexingLogger`: Secure file-based logging for indexation operations

### Configuration
- **Config System**: `src/Config/Config.php` and `src/Config/Settings.php`
- **Plugin Constants**: Defined in `plugin.php`
- **Default Index**: Configured in `config/meiliscout.php`

## Development Conventions

### PHP Standards
- **PHP 8.2+**: Modern PHP with strict typing (`declare(strict_types=1)`)
- **PSR Standards**: PSR-4 autoloading, PSR-11 container interface
- **Namespace Structure**: `Pollora\MeiliScout\` follows directory structure
- **Documentation**: Comprehensive PHPDoc comments required

### Code Organization
- **Contracts**: Interfaces in `src/Contracts/` for extensibility
- **Enums**: Domain enums in `src/Domain/Search/Enums/`
- **Validators**: Type validation in `src/Domain/Search/Validators/`
- **Service Providers**: Modular service registration pattern

### Admin Assets
- **Build Process**: Webpack with WordPress Scripts integration
- **Modern CSS**: TailwindCSS with PostCSS processing
- **Entry Point**: `resources/assets/main.js`

## Testing Strategy

### Test Structure
- **PestPHP**: Modern PHP testing framework
- **Unit Tests**: Individual component testing
- **Query Builder Tests**: Comprehensive search functionality testing
- **Mock Objects**: WordPress function mocking for isolated testing

### Test Organization
- Tests mirror `src/` structure
- Mock WordPress functions in `tests/Unit/Indexables/Post/QueryBuilder/MockWPQuery.php`
- Custom `TestCase` base class for shared functionality

## Key File Locations

### Core Files
- `plugin.php`: Plugin entry point
- `src/Foundation/Application.php`: Main application bootstrapper
- `src/Foundation/Container.php`: Dependency injection container

### Query System
- `src/Query/MeiliQueryBuilder.php`: Main query builder
- `src/Query/QueryIntegration.php`: WordPress integration
- `src/Query/Builders/`: Specialized query builders

### Configuration
- `config/meiliscout.php`: Plugin configuration
- `webpack.config.js`: Build configuration
- `composer.json`: PHP dependencies and autoloading

## Available Filters

The plugin provides several filters for customization:

- `meiliscout/skip_indexing`: Disable indexing globally (useful during imports)
- `meiliscout/indexables`: Customize or replace default indexables
- `meiliscout/post_single_indexer`: Customize or replace the PostSingleIndexer
- `meiliscout/bulk_batch_size`: Control batch size for bulk indexing (default: 500)
- `meiliscout/log_directory`: Customize log directory path
- `meiliscout/async_indexing_delay`: Delay before async queue processing (default: 300s)

## Environment Variables

- `MEILISCOUT_ASYNC_INDEXING`: Enable async indexing mode (`true`/`false`)
