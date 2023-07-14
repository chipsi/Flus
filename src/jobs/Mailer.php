<?php

namespace flusio\jobs;

/**
 * Job to send emails asynchronously.
 *
 * @author  Marien Fressinaud <dev@marienfressinaud.fr>
 * @license http://www.gnu.org/licenses/agpl-3.0.en.html AGPL
 */
class Mailer extends \Minz\Job
{
    public function __construct()
    {
        parent::__construct();
        $this->queue = 'mailers';
    }

    /**
     * Execute the given mailer action.
     */
    public function perform(string $mailer_class_name, string $mailer_action_name, mixed ...$args): void
    {
        $full_class_name = "\\flusio\\mailers\\{$mailer_class_name}";
        $mailer = new $full_class_name();
        $mailer->$mailer_action_name(...$args);
    }
}
