<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * Reports on moments and replies. flarum/flags only flags discussion posts
 * (its table requires a post), so Moments keeps its own, read by moderators
 * on the Reported tab.
 */
return Migration::createTableIfNotExists('moments_reports', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('entry_id');
    $table->unsignedInteger('user_id');
    $table->string('reason', 32);
    $table->text('detail')->nullable();
    $table->timestamp('created_at')->nullable();

    $table->unique(['entry_id', 'user_id'], 'moments_reports_once');
    $table->index('created_at', 'moments_reports_created_idx');

    $table->foreign('entry_id')->references('id')->on('moments_entries')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
