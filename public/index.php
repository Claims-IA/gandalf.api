<?php

/*
|--------------------------------------------------------------------------
| PHP 8.1 $_FILES compatibility shim
|--------------------------------------------------------------------------
|
| PHP 8.1 added a 'full_path' key to every $_FILES entry. The bundled
| Symfony HttpFoundation 3.0 (FileBag::FILE_KEYS) predates it: the extra key
| makes fixPhpFilesArray treat the entry as a nested array instead of a
| file, so Request::file() returns raw strings and file uploads crash.
| Stripping the key restores the exact shape Symfony 3 expects.
|
*/

foreach ($_FILES as &$phpUploadedFile) {
    if (is_array($phpUploadedFile)) {
        unset($phpUploadedFile['full_path']);
    }
}
unset($phpUploadedFile);

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| First we need to get an application instance. This creates an instance
| of the application / container and bootstraps the application so it
| is ready to receive HTTP / Console requests from the environment.
|
*/

$app = require __DIR__.'/../bootstrap/app.php';

/*
|--------------------------------------------------------------------------
| Run The Application
|--------------------------------------------------------------------------
|
| Once we have the application, we can handle the incoming request
| through the kernel, and send the associated response back to
| the client's browser allowing them to enjoy the creative
| and wonderful application we have prepared for them.
|
*/


$app->run();
