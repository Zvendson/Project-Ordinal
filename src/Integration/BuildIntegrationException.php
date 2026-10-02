<?php

declare(strict_types=1);

namespace Ordinal\Integration;

use RuntimeException;

/** Stops a build with a secret-free message while leaving its request identity available for recovery. */
final class BuildIntegrationException extends RuntimeException {}
