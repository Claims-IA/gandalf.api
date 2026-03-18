<?php
/**
 * DbTransfer
 *
 * Handles the export of an application's data from MongoDB to a downloadable
 * archive file. Uses the mongoexport CLI tool to dump the tables, decisions, and
 * changelogs collections filtered by application ID, then packages the resulting
 * JSON files into a .tar.gz archive stored in public/dump/. The archive is named
 * with a UTC timestamp and a random token to prevent enumeration. The public URL
 * for the archive is returned to the caller.
 *
 * @package App\Services
 */

namespace App\Services;

class DbTransfer
{
    /**
     * Export all data for an application to a compressed archive and return the download URL.
     *
     * Creates a unique temporary directory (using a MongoDB ObjectID for uniqueness),
     * runs mongoexport for each relevant collection with an application-scoped query,
     * then tars the resulting JSON files into a .tar.gz in public/dump/. The archive
     * filename includes a UTC timestamp and a 50-character random token so it cannot
     * be guessed by third parties.
     *
     * @param  mixed $appId  The application's MongoDB ObjectID.
     * @return string        Public URL where the archive can be downloaded.
     */
    public function export($appId)
    {
        // Use a random ObjectID as the temp directory name to avoid collisions when
        // multiple exports are triggered simultaneously
        $prefixTmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . strval(new \MongoDB\BSON\ObjectId) . DIRECTORY_SEPARATOR;
        // Define each collection and its application-scoped mongoexport query filter
        $collections = [
            'tables' => "'{applications: \"{$appId}\"}'",
            'decisions' => "'{applications: \"{$appId}\"}'",
            // Changelogs use a nested field path for the application ID
            'changelogs' => "'{\"model.attributes.applications\": \"{$appId}\"}'",
        ];
        foreach ($collections as $collection => $query) {
            exec(sprintf(
                "mongoexport -h %s --port %s -d %s -q %s -c %s --out %s",
                env('DB_HOST'),
                env('DB_PORT'),
                env('DB_DATABASE'),
                $query,
                $collection,
                $prefixTmpFile . $collection . '.json'
            ));
        }
        // Archive all exported JSON files; filename contains timestamp + random token
        $archiveName = gmdate('Y-m-d_H:i:s') . '-' . Hasher::getToken(50) . "Z.tar.gz";
        exec(sprintf("cd %s && tar -cvzf '%s' *.json", $prefixTmpFile, __DIR__ . "/../../public/dump/$archiveName"));

        return config('services.link.dump_project') . '/' . $archiveName;
    }
}
