<?php

namespace App\Services\RouterOs\Console;

use RuntimeException;

/**
 * The console declined an operation before anything reached the device — a protected row,
 * an action the menu does not offer, or a row that has gone since the page was drawn.
 */
class ConsoleRefusal extends RuntimeException {}
