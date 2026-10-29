<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/* Private bookmarks: only their owner ever reads them. */
return Migration::createTableIfNotExists('moments_bookmarks', function (Blueprint $table) {
    $table->unsignedInteger('user_id');
    $table->unsignedInteger('entry_id');
    $table->timestamp('created_at')->nullable();

    $table->primary(['user_id', 'entry_id']);
    $table->index('entry_id', 'moments_bookmarks_entry_idx');

    $table->foreign('entry_id')->references('id')->on('moments_entries')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
