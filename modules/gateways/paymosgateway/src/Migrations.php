<?php

declare(strict_types=1);

namespace PaymosWhmcs;

final class Migrations
{
    public const INVOICES_TABLE = 'mod_paymos_invoices';
    public const EVENTS_TABLE = 'mod_paymos_events';

    public static function ensure()
    {
        if (!class_exists('\\WHMCS\\Database\\Capsule')) {
            return;
        }

        $schema = \WHMCS\Database\Capsule::schema();

        if (!$schema->hasTable(self::INVOICES_TABLE)) {
            $schema->create(self::INVOICES_TABLE, static function ($table) {
                $table->increments('id');
                $table->integer('whmcs_invoice_id')->unsigned()->index();
                $table->string('paymos_invoice_id', 128)->unique();
                $table->string('external_order_id', 191)->unique();
                $table->string('environment', 16)->index();
                $table->string('project_id', 128)->index();
                $table->string('amount', 64);
                $table->string('currency', 16);
                $table->text('payment_url');
                $table->string('status', 64)->index();
                $table->integer('renew_count')->unsigned()->default(0);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (!$schema->hasTable(self::EVENTS_TABLE)) {
            $schema->create(self::EVENTS_TABLE, static function ($table) {
                $table->string('event_id', 128)->primary();
                $table->integer('expires_at')->unsigned()->index();
                $table->integer('created_at')->unsigned();
            });
        }
    }
}
