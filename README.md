# Statamic Sync

Pull Statamic content and assets from a remote environment to your local setup — no FTP, no SSH, just one artisan command.

## Installation

```bash
composer require marceli-to/statamic-sync
```

## Configuration

Publish the config:

```bash
php artisan vendor:publish --tag=statamic-sync-config
```

Add to your `.env` on **both** environments:

```env
STATAMIC_SYNC_TOKEN=your-shared-secret-here
```

Add to your `.env` on the **local** (pulling) environment:

```env
STATAMIC_SYNC_REMOTE=https://your-production-site.com
```

## Usage

### Pull everything (content + assets)

```bash
php artisan statamic:pull
```

### Pull only content

```bash
php artisan statamic:pull --only=content
```

### Pull only assets

```bash
php artisan statamic:pull --only=assets
```

### Dry run (see what would be synced)

```bash
php artisan statamic:pull --dry-run
```

### Skip confirmation

```bash
php artisan statamic:pull --force
```

## How It Works

1. The package registers a protected API endpoint (`/_sync/download`) on your remote site
2. The `statamic:pull` command hits that endpoint with your shared token
3. The remote zips up the requested directories and streams them back
4. Local extracts and replaces the directories

## Configuration Options

```php
// config/statamic-sync.php

return [
    'token' => env('STATAMIC_SYNC_TOKEN', ''),
    'remote' => env('STATAMIC_SYNC_REMOTE', ''),

    'paths' => [
        'content' => 'content',
        'assets' => 'public/assets',
    ],

    'route_prefix' => '_sync',
    'allowed_ips' => [], // Optional IP whitelist
];
```

## Security

- All requests require a valid bearer token
- Optional IP whitelisting
- The sync endpoint is only accessible with the correct token
- Use a strong, unique token (e.g. `openssl rand -hex 32`)

## License

MIT
