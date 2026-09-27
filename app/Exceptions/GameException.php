<?php

namespace App\Exceptions;

use RuntimeException;

/** A game rule was broken. Rendered as a flash message; the transaction that threw it wrote nothing. */
class GameException extends RuntimeException {}
