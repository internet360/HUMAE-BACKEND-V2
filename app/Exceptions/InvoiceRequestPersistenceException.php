<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A database failure while saving an invoice request, stripped of the SQL and
 * its bindings. The original QueryException carries the RFC, legal name and
 * email in its message, and the exception handler writes that to laravel.log.
 * It is deliberately not chained as `previous`.
 */
class InvoiceRequestPersistenceException extends RuntimeException {}
