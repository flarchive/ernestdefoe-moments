<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Topics are the #words in moments. `moment_count` counts only public,
 * visible moments, so the popular-topics widget never hints at what a
 * followers-only or private moment said.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('moments_topics')) {
            $schema->create('moments_topics', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 60);
                $table->string('name_key', 60)->unique('moments_topics_key_unique');
                $table->unsignedInteger('moment_count')->default(0);
                $table->timestamp('last_used_at')->nullable();

                $table->index(['moment_count', 'last_used_at'], 'moments_topics_popular_idx');
                $table->index('last_used_at', 'moments_topics_active_idx');
            });
        }

        if (! $schema->hasTable('moments_entry_topic')) {
            $schema->create('moments_entry_topic', function (Blueprint $table) {
                $table->unsignedInteger('entry_id');
                $table->unsignedInteger('topic_id');

                $table->primary(['entry_id', 'topic_id']);
                $table->index(['topic_id', 'entry_id'], 'moments_entry_topic_feed_idx');

                $table->foreign('entry_id')->references('id')->on('moments_entries')->cascadeOnDelete();
                $table->foreign('topic_id')->references('id')->on('moments_topics')->cascadeOnDelete();
            });
        }
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('moments_entry_topic');
        $schema->dropIfExists('moments_topics');
    },
];
