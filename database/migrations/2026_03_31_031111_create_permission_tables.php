<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Preserve the historical schema for upgrades/rollbacks. Application access
    // uses users.role/privileges; these legacy tables require no permission SDK.
    public function up(): void
    {
        foreach (['permissions', 'roles'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
        }

        foreach (['permissions' => 'permission_id', 'roles' => 'role_id'] as $entity => $foreignKey) {
            Schema::create('model_has_'.$entity, function (Blueprint $table) use ($entity, $foreignKey): void {
                $table->unsignedBigInteger($foreignKey);
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->index(['model_id', 'model_type'], 'model_has_'.$entity.'_model_id_model_type_index');
                $table->foreign($foreignKey)->references('id')->on($entity)->cascadeOnDelete();
                $table->primary([$foreignKey, 'model_id', 'model_type'], 'model_has_'.$entity.'_'.substr($foreignKey, 0, -3).'_model_type_primary');
            });
        }

        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
        });
    }

    public function down(): void
    {
        foreach (['role_has_permissions', 'model_has_roles', 'model_has_permissions', 'roles', 'permissions'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
