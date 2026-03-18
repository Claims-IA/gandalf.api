<?php
/**
 * Event
 *
 * Abstract base class for all domain events in the Gandalf API. Uses the
 * SerializesModels trait so that when an event is queued, any Eloquent model
 * properties are serialised by primary key and re-fetched when the job is
 * processed, rather than serialising the entire model state.
 *
 * @package App\Events
 */

namespace App\Events;

use Illuminate\Queue\SerializesModels;

abstract class Event
{
    use SerializesModels;
}
