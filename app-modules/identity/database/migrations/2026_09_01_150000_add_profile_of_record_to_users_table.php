<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The profile-of-record (ADR-0051 §2–3). `username` ships NOT NULL because
 * the platform is pre-release: the backfill below serves dev fixtures and
 * nothing else, so there is no claim flow. Unique case-insensitively via an
 * expression index — stored as typed, compared folded.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $reserved = ['admin', 'konventix', 'operator', 'api', 'www'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username', 64)->nullable()->after('name');
            $table->string('tagline', 140)->nullable()->after('username');
            $table->string('status_emoji', 32)->nullable()->after('tagline');
            $table->json('social_links')->nullable()->after('status_emoji');
        });

        $used = [];

        foreach (DB::table('users')->orderBy('id')->get(['id', 'name']) as $row) {
            $base = (string) Str::of($row->name)->ascii()->lower()
                ->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->substr(0, 24)->trim('-');

            if (strlen($base) < 3) {
                $base = 'member';
            }

            $candidate = $base;
            $suffix = 2;

            while (isset($used[$candidate]) || in_array($candidate, $this->reserved, true)) {
                $candidate = $base.'-'.$suffix++;
            }

            $used[$candidate] = true;

            DB::table('users')->where('id', $row->id)->update(['username' => $candidate]);
        }

        DB::statement('CREATE UNIQUE INDEX users_username_lower_unique ON users (lower(username))');
        DB::statement('ALTER TABLE users ALTER COLUMN username SET NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_username_lower_unique');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['username', 'tagline', 'status_emoji', 'social_links']);
        });
    }
};
