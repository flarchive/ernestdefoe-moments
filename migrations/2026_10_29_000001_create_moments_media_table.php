<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * Photos, video, voice notes and GIFs. A row is written when the file is
 * uploaded (entry_id still null) and claimed by the moment it is posted with;
 * unclaimed rows are pruned after a day.
 */
return Migration::createTableIfNotExists('moments_media', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('entry_id')->nullable();
    $table->unsignedInteger('user_id');
    $table->string('kind', 8); // image, video, audio, gif
    $table->string('source', 8); // local, fof, giphy
    $table->string('path', 255)->nullable(); // on Moments' own disk
    $table->string('url', 1024);
    $table->string('mime', 64)->nullable();
    $table->unsignedSmallInteger('width')->nullable();
    $table->unsignedSmallInteger('height')->nullable();
    $table->unsignedInteger('duration')->nullable(); // milliseconds
    $table->unsignedInteger('size')->nullable();
    $table->unsignedTinyInteger('position')->default(0);
    $table->timestamp('created_at')->nullable();

    $table->index(['entry_id', 'position'], 'moments_media_entry_idx');
    $table->index(['user_id', 'created_at'], 'moments_media_user_idx');

    $table->foreign('entry_id')->references('id')->on('moments_entries')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
