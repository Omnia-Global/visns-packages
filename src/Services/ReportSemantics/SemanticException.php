<?php

namespace Visnsstudio\VisnsPackages\Services\ReportSemantics;

use RuntimeException;

/**
 * A report definition the semantic layer refuses to compile.
 *
 * Everything this exception carries is safe to hand back to the client: the
 * messages describe the caller's own definition (an unknown field id, an
 * operator that does not suit the field's type, a missing parameter) and never
 * the underlying schema. Anything that would leak internals - a database
 * error, a bad registry - is thrown as a plain exception instead and handled
 * by the controller's `errorResponse()` helper, which hides the detail behind
 * a correlation id.
 *
 * @see \Visnsstudio\VisnsPackages\Controllers\ReportBuilderController::errorResponse()
 */
class SemanticException extends RuntimeException
{
    /**
     * HTTP status the controller should respond with.
     *
     * @var int
     */
    protected $status;

    /**
     * Per-problem detail: [['path' => 'adviser.naem', 'message' => '...'], ...]
     *
     * `path` is the offending dot-path, parameter id or definition key, so the
     * wizard can highlight the exact control that produced it.
     *
     * @var array<int, array{path: string|null, message: string}>
     */
    protected $errors;

    /**
     * @param string $message Summary message, client safe.
     * @param array<int, array{path: string|null, message: string}> $errors
     * @param int $status
     */
    public function __construct(
        string $message,
        array $errors = [],
        int $status = 422
    ) {
        parent::__construct($message);

        $this->errors = $errors;
        $this->status = $status;
    }

    /**
     * Build an exception describing a single offending path.
     *
     * @param string|null $path
     * @param string $message
     * @param int $status
     * @return static
     */
    public static function forPath(
        $path,
        string $message,
        int $status = 422
    ): self {
        $summary =
            $path === null || $path === ''
                ? $message
                : "{$message} (at: {$path})";

        return new static(
            $summary,
            [['path' => $path, 'message' => $message]],
            $status
        );
    }

    /**
     * @return int
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<int, array{path: string|null, message: string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
