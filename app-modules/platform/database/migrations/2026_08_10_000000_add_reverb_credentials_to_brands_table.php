<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every brand is one Reverb application on the shared websocket server
     * (ADR-0025). The brand's ULID doubles as the Reverb app id; key and
     * secret are provisioned by the model when a brand is created.
     */
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            // The reverb server looks connections up by key on every
            // handshake, so it must be unique and indexed.
            $table->string('reverb_app_key')->nullable()->unique();
            $table->string('reverb_app_secret')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropColumn(['reverb_app_key', 'reverb_app_secret']);
        });
    }
};
