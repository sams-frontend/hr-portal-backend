<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('uuid');
            $table->string('salutation')->nullable();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('gender')->nullable();
            $table->string('office_id')->unique()->nullable();
            $table->string('personal_email')->unique();
            $table->string('office_email')->unique()->nullable();
            $table->string('phone_no')->unique();
            $table->string('alt_phone_no')->unique()->nullable();
            $table->unsignedBigInteger('role_id')->default(1);
            $table->unsignedBigInteger('department_id')->index()->nullable();
            $table->unsignedBigInteger('designation_id')->index()->nullable();
            $table->date('date_of_joining')->nullable();
            $table->date('probation_end_date')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('marital_status')->nullable();
            $table->text('about')->nullable();
            $table->string('current_location')->nullable();
            $table->string('blood_grp')->nullable();
            $table->string('specially_abled')->nullable();
            $table->unsignedBigInteger('employee_type_id')->nullable();
            $table->unsignedBigInteger('reporting_manager_id')->nullable();
            $table->unsignedBigInteger('reporting_TL_id')->nullable();
            $table->boolean('is_disable')->default(false);
            $table->string('profile_picture')->nullable();
            $table->date('sepration_date')->nullable();
            $table->enum('sepration_status', ['active',  'inactive', 'reversed', 'resigned'])->default('active')->nullable(); //'on_notice',
            $table->enum('work_mode', ['remote', 'onsite', 'hybrid'])->default('onsite')->nullable(); //'on_notice',

            $table->rememberToken();
            $table->timestamps();

            // Define Foreign Keys
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            $table->foreign('department_id')->references('id')->on('departments')->onDelete('cascade');
            $table->foreign('designation_id')->references('id')->on('designations')->onDelete('cascade');
            $table->foreign('employee_type_id')->references('id')->on('employee_types')->onDelete('cascade');
            $table->foreign('reporting_manager_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('reporting_TL_id')->references('id')->on('users')->onDelete('cascade');
            //$table->timestamp('email_verified_at')->nullable();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
