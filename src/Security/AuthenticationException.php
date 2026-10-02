<?php

declare(strict_types=1);

namespace Ordinal\Security;

use RuntimeException;

/** Signals missing or invalid authentication without exposing credential details. */
final class AuthenticationException extends RuntimeException {}
