<?php

declare(strict_types=1);

namespace WebtreesShare\Migrations;

use Fisharebest\Webtrees\Schema\MigrationInterface;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;

/**
 * One table: a request and its (optional) response live in the same row.
 * The guest only ever sees request_data (a frozen snapshot taken at creation time),
 * never a live lookup of the record - so there is nothing else to store or join.
 */
class Migration0 implements MigrationInterface
{
    public function upgrade(): void
    {
        if (DB::schema()->hasTable('webtreesshare_request')) {
            return;
        }

        DB::schema()->create('webtreesshare_request', static function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('gedcom_id');
            $table->string('xref', 20);
            $table->integer('creator_user_id');
            $table->string('token', 64);
            $table->text('request_data');
            $table->text('response_data')->nullable();
            $table->enum('status', ['pending', 'answered', 'applied'])->default('pending');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('applied_at')->nullable();

            $table->unique('token');
            $table->index(['creator_user_id', 'status']);

            $table->foreign('gedcom_id')->references('gedcom_id')->on('gedcom');
            $table->foreign('creator_user_id')->references('user_id')->on('user');
        });
    }
}
