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
            $table->string('phone', 30)->nullable()->after('email');
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            $table->string('avatar_url', 500)->nullable()->after('password');
            $table->string('status', 30)->default('active')->after('avatar_url');
            $table->timestamp('last_login_at')->nullable()->after('status');

            $table->index('phone');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['phone']);
            $table->dropIndex(['status']);
            $table->dropColumn([
                'phone',
                'phone_verified_at',
                'avatar_url',
                'status',
                'last_login_at',
            ]);
        });
    }
};
