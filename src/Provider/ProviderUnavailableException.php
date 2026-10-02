<?php

declare(strict_types=1);

namespace Ordinal\Provider;

use RuntimeException;

/** Signals that current provider identity or permissions could not be verified. */
final class ProviderUnavailableException extends RuntimeException {}
