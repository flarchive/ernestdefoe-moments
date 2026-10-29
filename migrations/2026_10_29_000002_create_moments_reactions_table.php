<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/* One reaction per member per moment or reply. */
return Migration::createTableIfNotExists('moments_reactions', function (Blueprint $table) {
    $table->unsignedInteger('entry_id');
    $table->unsignedInteger('user_id');
    $table->string('reaction', 64);
    $table->timestamp('created_at')->nullable();

    $table->primary(['entry_id', 'user_id']);
    $table->index(['user_id', 'entry_id'], 'moments_reactions_user_idx');

    $table->foreign('entry_id')->references('id')->on('moments_entries')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
