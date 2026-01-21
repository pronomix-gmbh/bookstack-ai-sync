# BookStack OpenWebUI Sync

A composer-installable BookStack package to sync BookStack books, pages, and attachments into OpenWebUI knowledge bases using only BookStack's Laravel runtime.

## Install

```bash
composer require pronomix-gmbh/bookstack-ai-sync
```

Publish config (optional):

```bash
php artisan vendor:publish --tag=bookstack-openwebui-config
```

Publish views (optional):

```bash
php artisan vendor:publish --tag=bookstack-openwebui-views
```

Run migrations:

```bash
php artisan migrate
```

If auto-discovery is disabled, add the service provider to `config/app.php`:

```
Pronomix\BookStackOpenWebUISync\BookStackOpenWebUISyncServiceProvider::class,
```

## Configuration

Copy the env keys you need:

```env
OPENWEBUI_ENABLED=true
OPENWEBUI_INSTANCE_NAME=bookstack
OPENWEBUI_BASE_URL=https://openwebui.example.com
OPENWEBUI_API_KEY=your-api-key
OPENWEBUI_TIMEOUT=30
OPENWEBUI_VERIFY_TLS=true
OPENWEBUI_POLL_ENABLED=true
OPENWEBUI_POLL_INTERVAL=5
```

You can also configure these in the admin UI:

- `Settings` → `OpenWebUI Sync`

## Scheduler / Queue

This package stores tasks in the database and does not require a queue worker. To process tasks via cron:

```bash
* * * * * php /path/to/bookstack/artisan openwebui:process-queue >> /dev/null 2>&1
```

If you already run `php artisan schedule:run`, you can also register that cron and call `openwebui:process-queue` manually as needed.

Optional queue workers can be enabled by setting:

```env
OPENWEBUI_QUEUE_DISPATCH=true
```

## Commands

- `openwebui:process-queue` - Process pending sync tasks
- `openwebui:sync-all` - Enqueue sync for all books
- `openwebui:sync-book {bookId}` - Enqueue sync for a single book
- `openwebui:rebuild-book {bookId}` - Rebuild a single book (delete & re-upload)
- `openwebui:test-connection` - Test OpenWebUI connectivity
- `openwebui:install` - Run only this package's migrations (optional publish flags)
- `openwebui:uninstall` - Roll back and remove package tables (with safety flags)

## Behavior

- Each BookStack book becomes an OpenWebUI knowledge base named `${instance}:${bookname}`.
- Pages and attachments are uploaded as files into the matching knowledge base.
- Identity is stable across page renames by using `page_id` and `attachment_id` in external keys.
- Deletes are propagated to OpenWebUI.

## Admin UI

- Admin-only settings page
- Test connection, sync all, sync book, rebuild book
- Queue status and recent failures

## Development

Run tests:

```bash
vendor/bin/phpunit
```
