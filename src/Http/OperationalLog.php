<?php

declare(strict_types=1);

namespace Ordinal\Http;

/** Keeps fixed secret-free runtime diagnostics outside administrative audit views. */
final class OperationalLog
{
    /**
     * Records unavailable audit storage without exception messages, request bodies or credentials.
     *
     * @return void
     */
    public static function recordAuditFailure(): void
    {
        error_log(json_encode(['timestampUtc' => gmdate('Y-m-d\TH:i:s\Z'), 'event' => 'allocation_audit_unavailable'], JSON_THROW_ON_ERROR));
    }
}
