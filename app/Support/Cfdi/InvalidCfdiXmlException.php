<?php

declare(strict_types=1);

namespace App\Support\Cfdi;

use RuntimeException;

/**
 * The message is shown to the admin, so it is Spanish and never echoes
 * content taken from the uploaded file.
 */
class InvalidCfdiXmlException extends RuntimeException {}
