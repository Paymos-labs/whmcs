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
                $table->string('invoice_total', 64)->nullable();
                $table->string('currency', 16);
                $table->text('payment_url');
                $table->string('status', 64)->index();
                $table->integer('renew_count')->unsigned()->default(0);
                $table->string('paid_late_noted', 128)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        // Added after the first release: the WHMCS invoice total next to the
        // amount due (see GatewayLink::invoiceTotal). Older rows keep NULL.
        if (!$schema->hasColumn(self::INVOICES_TABLE, 'invoice_total')) {
            $schema->table(self::INVOICES_TABLE, static function ($table) {
                $table->string('invoice_total', 64)->nullable();
            });
        }

        // invoice-late-payment: the paid-late marker survives redelivery and restarts.
        if (!$schema->hasColumn(self::INVOICES_TABLE, 'paid_late_noted')) {
            $schema->table(self::INVOICES_TABLE, static function ($table) {
                $table->string('paid_late_noted', 128)->nullable();
            });
        }

        if (!$schema->hasTable(self::EVENTS_TABLE)) {
            $schema->create(self::EVENTS_TABLE, static function ($table) {
                $table->string('event_id', 128)->primary();
                $table->string('owner_token', 32)->default('');
                $table->integer('expires_at')->unsigned()->index();
                $table->integer('created_at')->unsigned();
            });
        }

        // Tables created before the owner-token reservation fix (A10-02) lack
        // the `owner_token` column. Old rows keep '' and never match a live
        // owner token, so they simply expire via their reservation TTL (<=300s)
        // — no data migration is needed, and the takeover window closes itself.
        if ($schema->hasTable(self::EVENTS_TABLE) && !$schema->hasColumn(self::EVENTS_TABLE, 'owner_token')) {
            try {
                $schema->table(self::EVENTS_TABLE, static function ($table) {
                    $table->string('owner_token', 32)->default('');
                });
            } catch (\Throwable $e) {
                // An installation whose ALTER failed keeps working until the
                // next request retries it; expired rows still age out by TTL.
            }
        }
    }
}
