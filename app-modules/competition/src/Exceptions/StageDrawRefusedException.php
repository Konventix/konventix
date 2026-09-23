<?php

declare(strict_types=1);

namespace Konventix\Competition\Exceptions;

use RuntimeException;

/**
 * A seeding or drawing of a stage was refused (design §7.5, D-CMP-20): the
 * stage is already drawn, a result has been recorded and the draw is frozen,
 * or a hand-made seed order names somebody who is not in the field.
 *
 * Named so the desk can tell these apart from every other `RuntimeException`
 * — a failed query is one too — and show the refusal as the answer to the
 * button that was pressed rather than as a 500. Each is a stale screen or a
 * rule the desk ran into, never a crash.
 *
 * Console-only, so the messages stay English (ADR-0001).
 */
final class StageDrawRefusedException extends RuntimeException {}
