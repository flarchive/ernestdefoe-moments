<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * Moments and their replies, one table. A reply carries the moment it belongs
 * to (`root_id`), the reply it answers (`parent_id`) and the top-level reply
 * whose thread it sits in (`thread_id`), so a page of replies and everything
 * under them is two indexed reads.
 *
 * `privacy` and `audience_user_id` are copied from the moment onto each reply
 * when it is written, so the one visibility rule reads the row it is looking
 * at and never has to join back to the moment.
 */
return Migration::createTableIfNotExists('moments_entries', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id');
    // The member whose followers may see this (the moment's author, also on replies).
    $table->unsignedInteger('audience_user_id');
    $table->unsignedInteger('root_id')->nullable();
    $table->unsignedInteger('parent_id')->nullable();
    $table->unsignedInteger('thread_id')->nullable();
    $table->unsignedTinyInteger('depth')->default(0);
    $table->unsignedInteger('repost_of_id')->nullable();
    // s9e XML from the forum's formatter; null for a repost without a comment.
    $table->mediumText('content')->nullable();
    // Plain lower-case text for search.
    $table->text('search_text')->nullable();
    $table->string('privacy', 16)->default('public');
    $table->unsignedInteger('reply_count')->default(0);
    $table->unsignedInteger('repost_count')->default(0);
    $table->unsignedInteger('reaction_count')->default(0);
    $table->timestamp('locked_at')->nullable();
    $table->timestamp('hidden_at')->nullable();
    $table->unsignedInteger('hidden_user_id')->nullable();
    $table->timestamp('edited_at')->nullable();
    $table->timestamp('created_at')->nullable();

    // The feeds: moments (parent_id null) newest first, by privacy, by author.
    $table->index(['parent_id', 'id'], 'moments_entries_parent_idx');
    $table->index(['privacy', 'parent_id', 'id'], 'moments_entries_privacy_idx');
    $table->index(['user_id', 'parent_id', 'id'], 'moments_entries_user_idx');
    $table->index(['audience_user_id', 'privacy'], 'moments_entries_audience_idx');
    // A moment's replies, and a thread's nested replies.
    $table->index(['root_id', 'id'], 'moments_entries_root_idx');
    $table->index(['thread_id', 'id'], 'moments_entries_thread_idx');
    $table->index(['repost_of_id', 'user_id'], 'moments_entries_repost_idx');
    $table->index('created_at', 'moments_entries_created_idx');
    $table->index('hidden_at', 'moments_entries_hidden_idx');

    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('root_id')->references('id')->on('moments_entries')->cascadeOnDelete();
    $table->foreign('parent_id')->references('id')->on('moments_entries')->cascadeOnDelete();
    $table->foreign('repost_of_id')->references('id')->on('moments_entries')->nullOnDelete();
});
