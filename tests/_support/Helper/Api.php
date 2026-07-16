<?php
namespace Helper;

// here you can define custom actions
// all public methods declared in helper class will be available in $I

class Api extends \Codeception\Module
{
    /**
     * Save the raw body of the last REST response to a file (binary-safe).
     * Used by the Excel export tests to write the downloaded .xlsx to disk.
     *
     * @param  string $path  Absolute destination path.
     */
    public function grabResponseToFile($path)
    {
        $response = $this->getModule('REST')->grabResponse();
        file_put_contents($path, $response);
    }

    /**
     * POST a multipart file upload through the underlying PhpBrowser client
     * (the REST module's sendPOST files argument requires the $_FILES shape).
     *
     * @param  string $url     Endpoint (relative to the suite url).
     * @param  string $path    Absolute path of the file to upload.
     * @param  array  $params  Extra form fields (e.g. ['force' => 1]).
     * @param  string $field   Form field name for the file.
     */
    public function sendFileMultipart($url, $path, array $params = [], $field = 'file')
    {
        $this->getModule('REST')->sendPOST($url, $params, [
            $field => [
                'name'     => basename($path),
                'type'     => 'application/octet-stream',
                'tmp_name' => $path,
                'size'     => filesize($path),
                'error'    => UPLOAD_ERR_OK,
            ],
        ]);
    }
}
