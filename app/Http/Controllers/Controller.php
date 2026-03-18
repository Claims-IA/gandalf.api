<?php
/**
 * Controller
 *
 * Base controller class for the Gandalf API. All application controllers that
 * do not extend the Nebo15 AbstractController should extend this class instead
 * of the Lumen base directly. Currently acts as a thin pass-through but provides
 * a central place to add shared controller behaviour in the future.
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use Laravel\Lumen\Routing\Controller as BaseController;

class Controller extends BaseController
{
    //
}
