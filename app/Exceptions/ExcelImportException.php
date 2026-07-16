<?php
/**
 * ExcelImportException
 *
 * Carries the full list of cell-addressed errors collected while parsing or
 * validating a round-trip Excel import. Rendered by TablesController::import()
 * as a 422 response with one entry per problem so the user can fix everything
 * in a single iteration.
 *
 * Each error entry: ['cell' => 'C7'|null, 'row' => int|null,
 * 'column' => 'C'|null, 'field' => string|null, 'message' => string].
 *
 * @package App\Exceptions
 */

namespace App\Exceptions;

class ExcelImportException extends \RuntimeException
{
    private array $errors;

    /**
     * @param array  $errors   List of cell-addressed error entries.
     * @param string $message  Summary message.
     */
    public function __construct(array $errors, string $message = 'Erreurs dans le fichier importé.')
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
