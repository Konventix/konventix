<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `laravel/passkeys` ships its own migration with an auto-increment id
     * and a `foreignIdFor()` on the user model; neither fits here (ADR-0014:
     * every model has a ULID primary key, `users.id` is a ULID). Same
     * columns otherwise, so the package's model, actions and route binding
     * work unchanged on top of `Models\Passkey`.
     *
     * Not brand-scoped: a passkey is a way into the account, and the account
     * spans the operator (ADR-0016). The *relying party* it was created under
     * is a property of the credential (WebAuthn binds it to the host), not a
     * column — see `Http\Middleware\BindPasskeyRelyingParty`.
     *
     * `user_id` carries a real foreign key for the same reason
     * `social_identities.user_id` does: Identity's own table referencing
     * Identity's own users, so ADR-0014's no-constraint rule for
     * *cross-module* references does not apply.
     */
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Base64url of the authenticator's credential id; what a login
            // assertion is looked up by, so unique across every account.
            $table->string('credential_id')->unique();
            $table->json('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
