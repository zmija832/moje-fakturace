<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoicePublicLinkMigrationTest extends TestCase
{
    use InteractsWithBusinessDatabases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshBusinessTestDatabases();
    }

    public function test_public_links_are_tenant_local_and_store_no_plaintext_token(): void
    {
        foreach (['business_1', 'business_2'] as $connection) {
            $this->assertTrue(Schema::connection($connection)->hasTable('invoice_public_links'));
            foreach (['uuid', 'invoice_id', 'token_hash', 'token_ciphertext', 'revoked_at'] as $column) {
                $this->assertTrue(Schema::connection($connection)->hasColumn('invoice_public_links', $column));
            }
            $this->assertFalse(Schema::connection($connection)->hasColumn('invoice_public_links', 'business_id'));
            $this->assertFalse(Schema::connection($connection)->hasColumn('invoice_public_links', 'public_token'));
            $foreign = DB::connection($connection)->selectOne("SELECT referenced_table_name FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND table_name = 'invoice_public_links' AND column_name = 'invoice_id' AND referenced_table_name IS NOT NULL");
            $this->assertSame('invoices', $foreign->referenced_table_name);
        }

        $this->assertFalse(Schema::connection('central')->hasTable('invoice_public_links'));
    }
}
