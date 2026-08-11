<?php

namespace App\Support;

/**
 * The hospital's approved violation & penalty table
 * (المخالفات والجزاءات المتعلقة بأوقات العمل والحضور الرسمي).
 *
 * Rows 1–4 are the lateness / early-leave tiers, row 5 is a delay that disrupts
 * the workflow and row 6 is leaving work during official hours. Columns are the
 * first → fourth occurrence of the same row for the same employee.
 */
final class ViolationPolicy
{
    public const ROW_UP_TO_15   = 1;
    public const ROW_15_TO_30   = 2;
    public const ROW_30_TO_60   = 3;
    public const ROW_OVER_60    = 4;
    public const ROW_DISRUPTION = 5;
    public const ROW_ABANDONING = 6;

    private const PENALTIES = [
        1 => [
            1 => 'تنبيه شفوي',
            2 => 'إنذار كتابي',
            3 => 'خصم مرتب ربع يوم عمل',
            4 => 'خصم مرتب نصف يوم عمل',
        ],
        2 => [
            1 => 'تنبيه مع خصم مدة التأخير',
            2 => 'خصم مرتب ربع يوم عمل',
            3 => 'خصم مرتب نصف يوم عمل',
            4 => 'خصم مرتب نصف يوم عمل + جزاء',
        ],
        3 => [
            1 => 'تنبيه مع خصم مدة التأخير',
            2 => 'خصم مرتب نصف يوم عمل',
            3 => 'خصم مرتب يوم عمل كامل',
            4 => 'إنذار كتابي نهائي مع خصم مرتب يومي عمل',
        ],
        4 => [
            1 => 'إنذار كتابي مع خصم مدة التأخير',
            2 => 'خصم مرتب يوم عمل كامل',
            3 => 'إنذار كتابي نهائي مع خصم مرتب 3 أيام عمل',
            4 => 'خصم مرتب 5 أيام عمل + رفع توصية بإنهاء الخدمة',
        ],
        5 => [
            1 => 'خصم مرتب يوم عمل كامل',
            2 => 'خصم مرتب يومي عمل',
            3 => 'خصم مرتب 3 أيام عمل',
            4 => 'خصم مرتب 4 أيام عمل',
        ],
        6 => [
            1 => 'عقوبة خصم مرتب نصف يوم عمل',
            2 => 'خصم مرتب يومي عمل',
            3 => 'إنذار كتابي نهائي مع خصم مرتب 3 أيام عمل',
            4 => 'خصم مرتب 5 أيام عمل + رفع توصية بإنهاء الخدمة',
        ],
    ];

    private const ARTICLES = [
        1 => 'المادة الأولى',
        2 => 'المادة الثانية',
        3 => 'المادة الثالثة',
        4 => 'المادة الرابعة',
        5 => 'المادة الخامسة',
        6 => 'المادة السادسة',
    ];

    private const ROW_DESCRIPTIONS = [
        1 => 'التأخير أو ترك العمل مبكراً لغاية 15 دقيقة',
        2 => 'التأخير أو ترك العمل مبكراً أكثر من 15 وحتى 30 دقيقة',
        3 => 'التأخير أو ترك العمل مبكراً أكثر من 30 وحتى 60 دقيقة',
        4 => 'التأخير أو ترك العمل مبكراً أكثر من 60 دقيقة',
        5 => 'تأخير أدى إلى تعطيل سير العمل أو الإضرار بمصلحة المؤسسة',
        6 => 'ترك العمل خلال ساعات الدوام الرسمي بدون إذن',
    ];

    /** How many minutes of lateness / early leave map to which row of the table. */
    public static function rowForMinutes(int $minutes): int
    {
        if ($minutes <= 15) return self::ROW_UP_TO_15;
        if ($minutes <= 30) return self::ROW_15_TO_30;
        if ($minutes <= 60) return self::ROW_30_TO_60;
        return self::ROW_OVER_60;
    }

    /** The penalty for the n-th occurrence of a row; occurrences past the 4th keep the 4th penalty. */
    public static function penalty(?int $row, int $occurrence): string
    {
        if ($row === null) {
            return self::absencePenalty($occurrence);
        }

        return self::PENALTIES[$row][min(max($occurrence, 1), 4)]
            ?? 'يرجى مراجعة لجنة الشؤون الإدارية';
    }

    /**
     * Absence is not part of the lateness table, so it is escalated on its own
     * ladder and always lands on the HR review queue before any deduction.
     */
    public static function absencePenalty(int $occurrence): string
    {
        return match (min(max($occurrence, 1), 4)) {
            1 => 'إنذار كتابي مع خصم مرتب يوم الغياب',
            2 => 'خصم مرتب يومي عمل',
            3 => 'إنذار كتابي نهائي مع خصم مرتب 3 أيام عمل',
            default => 'خصم مرتب 5 أيام عمل + رفع توصية بإنهاء الخدمة',
        };
    }

    public static function article(?int $row): string
    {
        return $row === null
            ? 'الغياب بدون إجازة'
            : (self::ARTICLES[$row] ?? '—');
    }

    public static function rowDescription(?int $row): ?string
    {
        return $row === null ? null : (self::ROW_DESCRIPTIONS[$row] ?? null);
    }

    public static function typeLabel(string $type, ?int $row = null): string
    {
        return match ($type) {
            'late'         => 'تأخير عن الدوام',
            'early_leave'  => 'ترك العمل مبكراً',
            'absent'       => 'غياب بدون إجازة',
            'disciplinary' => 'مخالفة انضباطية',
            'cctv'         => 'مخالفة مرصودة بكاميرات المراقبة',
            default        => self::rowDescription($row) ?? 'مخالفة وظيفية',
        };
    }
}
