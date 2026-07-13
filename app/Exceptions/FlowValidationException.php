<?php
/**
 * FlowValidationException
 *
 * Thrown by FlowRepository when a flow graph is malformed: unknown table
 * reference, a target field that doesn't exist, an incompatible wire, a cycle,
 * etc. Carries the full list of problems so the exception handler can render a
 * 422 with a structured `errors` array, like request validation.
 *
 * @package App\Exceptions
 */

namespace App\Exceptions;

class FlowValidationException extends \Exception
{
    /**
     * @var array  Human-readable descriptions of each graph problem.
     */
    private $errors;

    /**
     * @var string|null  Id of the FlowRun recorded for a failed run, so the
     *                   client can correlate the 422 with the persisted trace.
     *                   Null for write-time (save) validation, which has no run.
     */
    private $flowRunId;

    /**
     * @param  array $errors
     */
    public function __construct(array $errors)
    {
        parent::__construct('Flow graph validation failed.');
        $this->errors = $errors;
    }

    /**
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Attach the id of the FlowRun that recorded this failed execution.
     *
     * @param  string|null $flowRunId
     * @return $this
     */
    public function setFlowRunId($flowRunId)
    {
        $this->flowRunId = $flowRunId;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getFlowRunId()
    {
        return $this->flowRunId;
    }
}
