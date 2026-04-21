<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

abstract class Controller
{
    use ApiResponse;
    use AuthorizesRequests;
    use ValidatesRequests;
}
