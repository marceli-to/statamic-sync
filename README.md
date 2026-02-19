# Statamic Sync

Pull Statamic content and assets from a remote environment to your local setup — no FTP, no SSH, just one artisan command.

## How It Works

1. The package exposes a protected endpoint on your remote site
2. When you run `statamic:pull`, it compares local and remote files via hash manifests
3. Only new, changed, and deleted files are synced — unchanged files are skipped
4. Files are streamed as compressed tar.gz archives directly from the server

First run downloads everything. Subsequent runs only sync the diff.

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12
- `tar` available on both server and local machine (standard on Linux/macOS)

## Installation

Install the package on **both** your local and remote environments:

```bash
composer require marceli-to/statamic-sync
```

## Configuration

Publish the config (optional — defaults work out of the box):

```bash
php artisan vendor:publish --tag=statamic-sync-config
```

### Environment Variables

**Both environments** (local + remote):

```env
STATAMIC_SYNC_TOKEN=your-shared-secret-here
```

Generate a secure token:

```bash
openssl rand -hex 32
```

**Local environment only:**

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

### Dry run (see what would change)

```bash
php artisan statamic:pull --dry-run
```

Output shows exactly what would happen:

```
content:
  Unchanged: 52 files
  Changed:   3 files
  New:       2 files
  Deleted:   1 files
  Download:  12.5 KB

assets:
  Unchanged: 820 files
  New:       3 files
  Download:  4.2 MB
```

### Force full sync (skip delta comparison)

```bash
php artisan statamic:pull --full
```

### Skip confirmation

```bash
php artisan statamic:pull --force
```

## Configuration Options

```php
// config/statamic-sync.php

return [
    // Shared secret for authentication (required on both sides)
    'token' => env('STATAMIC_SYNC_TOKEN', ''),

    // Remote URL to pull from (local side only)
    'remote' => env('STATAMIC_SYNC_REMOTE', ''),

    // Directories to sync (relative to project root)
    'paths' => [
        'content' => 'content',
        'assets' => 'public/assets',
    ],

    // URL prefix for the sync endpoint
    'route_prefix' => '_sync',

    // Optional IP whitelist (empty = allow all, token still required)
    'allowed_ips' => [],
];
```

## Updating

Update only this package:

```bash
composer update marceli-to/statamic-sync
```

Remember to update on **both** local and remote when upgrading.

## Security

- All requests require a valid bearer token
- Optional IP whitelisting for additional protection
- Use a strong, unique token — `openssl rand -hex 32`
- The sync endpoints are only accessible with a valid token
- Directory traversal protection on file serving

## License

MIT
