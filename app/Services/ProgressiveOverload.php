<?php

namespace App\Services;

/**
 * Single source of truth for progressive overload exercise count — PHP side.
 *
 * Mirrors fitnease-ml/config/progressive_overload.py exactly.
 * If professor requirements change, update BOTH files together.
 *
 * Fitness level → completed session count → exercises per day:
 *   Beginner:     0-5  → 4,  6-15 → 5,  16+ → 6
 *   Intermediate: 0-5  → 6,  6-15 → 7,  16+ → 8
 *   Advanced:     0-5  → 8,  6-15 → 10, 16+ → 12
 *
 * Do NOT duplicate this logic in other files.
 */
class ProgressiveOverload
{
    /**
     * [min_sessions_inclusive, max_sessions_exclusive, exercise_count]
     * max = null means "no upper bound" (16+)
     */
    private const RANGES = [
        'beginner'     => [[0, 6, 4],  [6, 16, 5],  [16, null, 6]],
        'intermediate' => [[0, 6, 6],  [6, 16, 7],  [16, null, 8]],
        'advanced'     => [[0, 6, 8],  [6, 16, 10], [16, null, 12]],
    ];

    private const LEVEL_ALIASES = [
        'medium' => 'intermediate',
        'expert' => 'advanced',
    ];

    /**
     * Return exercise count for a fitness level and cumulative session count.
     */
    public static function getExerciseCount(string $fitnessLevel, int $sessionCount): int
    {
        $level = strtolower($fitnessLevel ?: 'beginner');
        $level = self::LEVEL_ALIASES[$level] ?? $level;
        $ranges = self::RANGES[$level] ?? self::RANGES['beginner'];

        foreach ($ranges as [$low, $high, $count]) {
            if ($sessionCount >= $low && ($high === null || $sessionCount < $high)) {
                return $count;
            }
        }

        return 4; // hard fallback — should never be reached
    }

    /**
     * Return the base (Tier 1) exercise count for a fitness level.
     * Equivalent to getExerciseCount($level, 0).
     */
    public static function getBaseCount(string $fitnessLevel): int
    {
        return self::getExerciseCount($fitnessLevel, 0);
    }

    /**
     * Return [min, max] exercise count bounds for a fitness level.
     * Mirrors EXERCISE_COUNT_BOUNDS in progressive_overload.py.
     * Used for validating whether an existing plan has out-of-range counts.
     *
     * @return array{0: int, 1: int}
     */
    public static function getExerciseBounds(string $fitnessLevel): array
    {
        $level = strtolower($fitnessLevel ?: 'beginner');
        $level = self::LEVEL_ALIASES[$level] ?? $level;

        return match($level) {
            'intermediate' => [6, 8],
            'advanced'     => [8, 12],
            default        => [4, 6],
        };
    }

    /**
     * Return the progressive overload tier (1/2/3) for a session count.
     *   Tier 1: 0-5 sessions
     *   Tier 2: 6-15 sessions
     *   Tier 3: 16+ sessions
     */
    public static function getSessionTier(int $sessionCount): int
    {
        if ($sessionCount < 6) return 1;
        if ($sessionCount < 16) return 2;
        return 3;
    }
}
