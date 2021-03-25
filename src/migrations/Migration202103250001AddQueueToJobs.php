<?php

namespace flusio\migrations;

class Migration202103250001AddQueueToJobs
{
    public function migrate()
    {
        $database = \Minz\Database::get();

        $database->exec(<<<'SQL'
            ALTER TABLE jobs
            ADD COLUMN queue TEXT NOT NULL DEFAULT 'default';
        SQL);

        return true;
    }

    public function rollback()
    {
        $database = \Minz\Database::get();

        $database->exec(<<<'SQL'
            ALTER TABLE jobs
            DROP COLUMN queue;
        SQL);

        return true;
    }
}
