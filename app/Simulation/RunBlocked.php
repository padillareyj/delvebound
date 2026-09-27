<?php

namespace App\Simulation;

use RuntimeException;

/** A condition that stops delve:run with exit code 2 (DESIGN.md §10.2). */
class RunBlocked extends RuntimeException {}
