<?php
/**
 * Decisions\Make Event
 *
 * Fired by the Scoring service immediately after a Decision document is persisted.
 * Carries the Decision model to the EventListener which uses it to notify Intercom
 * and Mixpanel of the analytics event. This decouples the decision evaluation logic
 * from the analytics side-effects.
 *
 * @package App\Events\Decisions
 */

namespace App\Events\Decisions;

use App\Models\Decision;

class Make
{
    public $decision;

    public function __construct(Decision $decision)
    {
        $this->decision = $decision;
    }
}
