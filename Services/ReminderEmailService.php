<?php

namespace App\Services;

use App\Models\Department;
use Illuminate\Support\Facades\Mail;

class ReminderEmailService
{
    private const MONTHS_AR = [
        1=>'يناير',2=>'فبراير',3=>'مارس',4=>'أبريل',
        5=>'مايو',6=>'يونيو',7=>'يوليو',8=>'أغسطس',
        9=>'سبتمبر',10=>'أكتوبر',11=>'نوفمبر',12=>'ديسمبر',
    ];

    public function sendToOne(Department $dep, int $month, int $year): array
    {
        if (!$dep->chairman_email) {
            return ['success' => false, 'message' => 'لا يوجد بريد إلكتروني لهذا القسم'];
        }

        $monthName = self::MONTHS_AR[$month] ?? $month;

        try {
            Mail::send([], [], function ($message) use ($dep, $monthName, $year) {
                $message->to($dep->chairman_email, $dep->chairman_name ?? $dep->name)
                    ->subject("تذكير: رفع جدول دوام {$dep->name} — {$monthName} {$year}")
                    ->html($this->buildEmailHtml($dep, $monthName, $year));
            });

            return [
                'success' => true,
                'message' => "تم إرسال التذكير إلى {$dep->chairman_email}",
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'فشل الإرسال: ' . $e->getMessage(),
            ];
        }
    }

    public function sendToAllPending(array $departments, int $month, int $year): array
    {
        $results = [];
        foreach ($departments as $dep) {
            $results[] = array_merge(
                ['department' => $dep->name],
                $this->sendToOne($dep, $month, $year)
            );
        }
        return $results;
    }

    private function buildEmailHtml(Department $dep, string $monthName, int $year): string
    {
        $chairName = $dep->chairman_name ?? 'السيد/ة رئيس القسم';
        $depName   = $dep->name;

        return <<<HTML
        <!DOCTYPE html>
        <html dir="rtl" lang="ar">
        <head><meta charset="UTF-8">
        <style>
          body{font-family:Tahoma,Arial,sans-serif;direction:rtl;background:#f5f5f5;margin:0;padding:20px}
          .container{max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;
            box-shadow:0 4px 16px rgba(0,0,0,.1)}
          .header{background:linear-gradient(135deg,#5C0A15,#C01C2C);color:#fff;padding:24px 28px;text-align:center}
          .header h1{margin:0;font-size:20px}
          .header p{margin:6px 0 0;opacity:.85;font-size:13px}
          .body{padding:28px}
          .body p{font-size:15px;line-height:1.8;color:#333;margin:0 0 14px}
          .highlight{background:#FEF9E7;border-right:4px solid #C01C2C;padding:14px 16px;border-radius:6px;margin:16px 0}
          .highlight strong{color:#5C0A15}
          .footer{background:#f9f4f4;padding:16px 28px;text-align:center;font-size:12px;color:#888;
            border-top:1px solid #eee}
        </style>
        </head>
        <body>
        <div class="container">
          <div class="header">
            <h1>مستشفى الإمام الحسن المجتبى (ع)</h1>
            <p>نظام الموارد البشرية — تذكير تلقائي</p>
          </div>
          <div class="body">
            <p>السلام عليكم ورحمة الله وبركاته،</p>
            <p>الأستاذ/ة <strong>{$chairName}</strong>، رئيس قسم <strong>{$depName}</strong></p>
            <div class="highlight">
              <strong>⚠️ تذكير:</strong> لم يتم رفع جدول دوام قسمكم لشهر
              <strong>{$monthName} {$year}</strong> حتى الآن.
            </div>
            <p>يُرجى رفع الجدول في أقرب وقت ممكن من خلال نظام الموارد البشرية،
               حتى يتسنى معالجة بيانات الحضور وحساب الرواتب في الوقت المحدد.</p>
            <p>في حال واجهتكم أي مشكلة، يُرجى التواصل مع قسم الموارد البشرية.</p>
            <p>مع التحيات،<br><strong>قسم الموارد البشرية</strong></p>
          </div>
          <div class="footer">
            هذا البريد تلقائي من نظام HR — مستشفى الإمام الحسن المجتبى (ع)
          </div>
        </div>
        </body>
        </html>
        HTML;
    }
}
