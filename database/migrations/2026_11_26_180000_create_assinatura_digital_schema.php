<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS assinatura_digital');

        if (! Schema::hasTable('assinatura_digital.pedido')) {
            Schema::create('assinatura_digital.pedido', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('titulo', 255);
                $table->string('cenario', 40)->default('manual'); // unica | multipla | manual
                $table->string('status', 40)->default('rascunho');
                $table->unsignedInteger('status_libresign')->nullable();
                $table->string('libresign_file_id', 64)->nullable();
                $table->uuid('libresign_uuid')->nullable()->unique();
                $table->string('codigo_validacao', 32)->nullable()->index();
                $table->string('url_validacao', 500)->nullable();
                $table->jsonb('signatarios')->nullable();
                $table->jsonb('meta')->nullable();
                $table->unsignedBigInteger('solicitante_id')->nullable();
                $table->timestampTz('assinado_em')->nullable();
                $table->timestampsTz();
            });
        }

        if (! Schema::hasTable('assinatura_digital.evento')) {
            Schema::create('assinatura_digital.evento', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('pedido_id');
                $table->string('tipo', 80);
                $table->text('mensagem')->nullable();
                $table->jsonb('payload')->nullable();
                $table->timestampTz('created_at')->useCurrent();

                $table->foreign('pedido_id')
                    ->references('id')
                    ->on('assinatura_digital.pedido')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assinatura_digital.evento');
        Schema::dropIfExists('assinatura_digital.pedido');
        DB::statement('DROP SCHEMA IF EXISTS assinatura_digital CASCADE');
    }
};
