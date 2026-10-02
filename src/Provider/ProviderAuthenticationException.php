<?php

declare(strict_types=1);

namespace Ordinal\Provider;

use RuntimeException;

/** Signals rejected provider credentials without including upstream diagnostics or secrets. */
final class ProviderAuthenticationException extends RuntimeException {}
