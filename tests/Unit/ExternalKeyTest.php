<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Tests\Unit;

use Pronomix\BookStackOpenWebUISync\Services\OpenWebUISyncService;
use Pronomix\BookStackOpenWebUISync\Tests\TestCase;

class ExternalKeyTest extends TestCase
{
    public function test_external_keys_include_instance_and_book_slug(): void
    {
        $this->app['config']->set('bookstack-openwebui.instance_name', 'acme');

        $sync = app(OpenWebUISyncService::class);

        $this->assertSame('acme:my-book:page:42', $sync->externalKeyForPage('my-book', 42));
        $this->assertSame('acme:my-book:attachment:99', $sync->externalKeyForAttachment('my-book', 99));
    }
}
