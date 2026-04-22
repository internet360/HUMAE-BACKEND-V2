<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('invitation_token', 80)->nullable()->after('status');
            $table->timestamp('invitation_expires_at')->nullable()->after('invitation_token');
            $table->timestamp('invitation_accepted_at')->nullable()->after('invitation_expires_at');
            $table->foreignId('invited_by_user_id')->nullable()->after('invitation_accepted_at')
                ->constrained('users')->nullOnDelete();

            $table->index('invitation_token', 'users_invitation_token_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_invitation_token_index');
            $table->dropConstrainedForeignId('invited_by_user_id');
            $table->dropColumn([
                'invitation_token',
                'invitation_expires_at',
                'invitation_accepted_at',
            ]);
        });
    }
};
