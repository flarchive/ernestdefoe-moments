<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * MySQL and MariaDB search moments through a FULLTEXT index. PostgreSQL and
 * SQLite have no portable equivalent without an extension, and search there
 * is a LIKE per word over the moments the feed already narrowed.
 */
return [
    'up' => function (Builder $schema) {
        if (in_array($schema->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $schema->table('moments_entries', function (Blueprint $table) {
                $table->fullText('search_text', 'moments_entries_search_fulltext');
            });
        }
    },
    'down' => function (Builder $schema) {
        if (in_array($schema->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $schema->table('moments_entries', function (Blueprint $table) {
                $table->dropFullText('moments_entries_search_fulltext');
            });
        }
    },
];
