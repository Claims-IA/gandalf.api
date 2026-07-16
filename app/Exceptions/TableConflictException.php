<?php
/**
 * TableConflictException
 *
 * Optimistic-lock rejection: the table changed on the server after the Excel
 * file was exported. Rendered by TablesController::import() as HTTP 409 with
 * both timestamps so the client can offer "re-export" or "force overwrite".
 *
 * @package App\Exceptions
 */

namespace App\Exceptions;

class TableConflictException extends \RuntimeException
{
    private string $serverUpdatedAt;
    private string $fileExportedAt;

    public function __construct(string $serverUpdatedAt, string $fileExportedAt)
    {
        parent::__construct(
            'La table a été modifiée sur le serveur depuis l\'export du fichier. '
            . 'Ré-exportez le fichier ou renvoyez l\'import avec force=1 pour écraser.'
        );
        $this->serverUpdatedAt = $serverUpdatedAt;
        $this->fileExportedAt = $fileExportedAt;
    }

    public function getServerUpdatedAt(): string
    {
        return $this->serverUpdatedAt;
    }

    public function getFileExportedAt(): string
    {
        return $this->fileExportedAt;
    }
}
