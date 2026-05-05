<?php

declare(strict_types=1);

namespace Newss;

/**
 * In-DB Ring-Buffer-Logger fuer Plugin-Diagnose.
 *
 * Speichert maximal MAX_ENTRIES Log-Zeilen in einem einzigen Option-Row
 * (autoload=no, JSON-encoded). Verbose-Toggle entscheidet ob neben
 * Errors/Warnings auch Info-Level-Eintraege gespeichert werden.
 *
 * Parallele Writes via wpdb::update on the JSON-Blob -- nicht atomar,
 * letzte-schreibt-gewinnt. Fuer Diagnose-Logs OK, kein Audit-Log-Anspruch.
 */
final class Logger
{
    public const OPTION_BUFFER  = 'newss_log_buffer';
    public const OPTION_ENABLED = 'newss_log_enabled';
    public const MAX_ENTRIES    = 1000;

    public const LEVEL_INFO  = 'info';
    public const LEVEL_WARN  = 'warn';
    public const LEVEL_ERROR = 'error';

    public static function log(string $level, string $message, array $context = []): void
    {
        $verbose = (int) get_option(self::OPTION_ENABLED, 0) === 1;
        // Errors + Warns immer loggen, Info nur wenn verbose=on
        if ($level === self::LEVEL_INFO && !$verbose) {
            return;
        }

        $entry = [
            'ts'      => microtime(true),
            'level'   => $level,
            'message' => mb_substr($message, 0, 1000),
            'context' => $context !== [] ? array_slice($context, 0, 10, true) : null,
        ];

        $buffer = get_option(self::OPTION_BUFFER, []);
        if (!is_array($buffer)) {
            $buffer = [];
        }
        $buffer[] = $entry;
        // Ring-Buffer trimmen
        if (count($buffer) > self::MAX_ENTRIES) {
            $buffer = array_slice($buffer, -self::MAX_ENTRIES);
        }
        update_option(self::OPTION_BUFFER, $buffer, false);

        // Zusaetzlich PHP-error_log fuer ext. Tools/Tail
        error_log('[newss:' . $level . '] ' . $message);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log(self::LEVEL_INFO, $message, $context);
    }

    public static function warn(string $message, array $context = []): void
    {
        self::log(self::LEVEL_WARN, $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * @return array<int,array{ts:float,level:string,message:string,context:?array}>
     */
    public static function all(): array
    {
        $buffer = get_option(self::OPTION_BUFFER, []);
        return is_array($buffer) ? $buffer : [];
    }

    public static function clear(): void
    {
        update_option(self::OPTION_BUFFER, [], false);
    }
}
