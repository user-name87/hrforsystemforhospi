<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Violation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppNotificationService
{
    private string $apiUrl;
    private string $apiKey;
    private string $senderNumber;

    public function __construct()
    {
        $this->apiUrl = config('services.whatsapp.api_url', 'https://graph.facebook.com/v18.0');
        $this->apiKey = config('services.whatsapp.api_key');
        $this->senderNumber = config('services.whatsapp.sender_number');
    }

    /**
     * Send violation notification to employee
     */
    public function sendViolationNotification(Employee $employee, Violation $violation): array
    {
        $phoneNumber = $this->formatPhoneNumber($employee->phone_number);
        
        if (!$phoneNumber) {
            return [
                'success' => false,
                'message' => 'Employee phone number not available or invalid',
            ];
        }

        $message = $this->buildViolationMessage($employee, $violation);

        try {
            $response = $this->sendWhatsAppMessage($phoneNumber, $message);

            // Log the notification
            Log::info('WhatsApp violation notification sent', [
                'employee_id' => $employee->employee_id,
                'violation_id' => $violation->id,
                'phone' => $phoneNumber,
                'response' => $response,
            ]);

            return [
                'success' => true,
                'message' => 'WhatsApp notification sent successfully',
                'response' => $response,
            ];
        } catch (\Exception $e) {
            Log::error('WhatsApp notification failed', [
                'employee_id' => $employee->employee_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to send WhatsApp notification: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Send disciplinary action notification
     */
    public function sendDisciplinaryNotification(Employee $employee, array $disciplinaryAction): array
    {
        $phoneNumber = $this->formatPhoneNumber($employee->phone_number);
        
        if (!$phoneNumber) {
            return [
                'success' => false,
                'message' => 'Employee phone number not available or invalid',
            ];
        }

        $message = $this->buildDisciplinaryMessage($employee, $disciplinaryAction);

        try {
            $response = $this->sendWhatsAppMessage($phoneNumber, $message);

            Log::info('WhatsApp disciplinary notification sent', [
                'employee_id' => $employee->employee_id,
                'action_type' => $disciplinaryAction['action_type'],
                'phone' => $phoneNumber,
                'response' => $response,
            ]);

            return [
                'success' => true,
                'message' => 'WhatsApp disciplinary notification sent successfully',
                'response' => $response,
            ];
        } catch (\Exception $e) {
            Log::error('WhatsApp disciplinary notification failed', [
                'employee_id' => $employee->employee_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to send WhatsApp notification: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Send bulk daily violation notifications
     */
    public function sendDailyViolationNotifications(array $violations): array
    {
        $results = [];
        
        foreach ($violations as $violation) {
            $employee = Employee::where('employee_id', $violation['employee_id'])->first();
            if ($employee) {
                $violationModel = Violation::find($violation['id']);
                if ($violationModel) {
                    $results[] = $this->sendViolationNotification($employee, $violationModel);
                }
            }
        }

        return [
            'total' => count($violations),
            'sent' => count(array_filter($results, fn($r) => $r['success'])),
            'failed' => count(array_filter($results, fn($r) => !$r['success'])),
            'results' => $results,
        ];
    }

    /**
     * Build Arabic violation message template
     */
    private function buildViolationMessage(Employee $employee, Violation $violation): string
    {
        $violationDate = \Carbon\Carbon::parse($violation->incident_date)->format('Y/m/d');
        $articleNumber = $this->getArticleNumber($violation->violation_row);
        
        return <<<MESSAGE
مرحباً {$employee->name}،
نود إعلامكم بأنه تم تسجيل مخالفة وظيفية بحقكم وفقاً لسجلات الحضور واللوائح المعتمدة لدى المؤسسة.
تفاصيل المخالفة:
• الرقم الوظيفي: {$employee->employee_id}
• نوع المخالفة: {$this->getViolationTypeDescription($violation)}
• تاريخ المخالفة: {$violationDate}
• تفاصيل المخالفة: {$violation->notes ?? '—'}
• المادة: {$articleNumber}
الجزاء المترتب:
{$violation->penalty}
تم تسجيل المخالفة والجزاء في نظام الموارد البشرية وفقاً للائحة المخالفات والجزاءات المعتمدة.
في حال كان لديكم اعتراض أو ملاحظات على المخالفة، يرجى اتباع إجراءات الاعتراض المعتمدة لدى إدارة الموارد البشرية.
مع كامل الاحترام،
فريق الموارد البشرية
MESSAGE;
    }

    /**
     * Build disciplinary action message
     */
    private function buildDisciplinaryMessage(Employee $employee, array $action): string
    {
        $severityMap = [
            'low' => 'منخفضة',
            'medium' => 'متوسطة',
            'high' => 'عالية',
        ];
        $severityText = $severityMap[$action['severity']] ?? '—';

        return <<<MESSAGE
مرحباً {$employee->name}،
نود إعلامكم بأنه تم تسجيل إجراء تأديبي بحقكم من قبل إدارة الموارد البشرية.
تفاصيل الإجراء:
• الرقم الوظيفي: {$employee->employee_id}
• نوع الإجراء: {$action['action_type']}
• درجة الخطورة: {$severityText}
• ملاحظات: {$action['note']}
تم تسجيل الإجراء في ملفكم الوظيفي وفقاً للوائح المعتمدة.
في حال كان لديكم اعتراض، يرجى مراجعة إدارة الموارد البشرية.
مع كامل الاحترام،
فريق الموارد البشرية
MESSAGE;
    }

    /**
     * Send actual WhatsApp message via API
     */
    private function sendWhatsAppMessage(string $phoneNumber, string $message): array
    {
        // Using WhatsApp Business API format
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $phoneNumber,
            'type' => 'text',
            'text' => [
                'body' => $message,
            ],
        ];

        $response = Http::withToken($this->apiKey)
            ->post("{$this->apiUrl}/{$this->senderNumber}/messages", $payload);

        if (!$response->successful()) {
            throw new \Exception('WhatsApp API error: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Format phone number for WhatsApp (ensure it has country code)
     */
    private function formatPhoneNumber(?string $phone): ?string
    {
        if (!$phone) return null;

        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Handle Iraq phone numbers
        // Iraq numbers: 07701234567 (11 digits) or 0770123456 (10 digits)
        // Should become: +9647701234567 (13 digits total)
        
        // If starts with 07, remove the 0
        if (substr($phone, 0, 2) === '07') {
            $phone = substr($phone, 1);
        }
        
        // If starts with 7 and has 10-11 digits, add Iraq country code
        if (substr($phone, 0, 1) === '7' && (strlen($phone) === 10 || strlen($phone) === 11)) {
            $phone = '964' . $phone;
        }

        // Add + for international format
        if (substr($phone, 0, 1) !== '+') {
            $phone = '+' . $phone;
        }

        // Validate phone number format (basic validation for Iraq: +9647XXXXXXXXX)
        if (!preg_match('/^\+9647\d{9}$/', $phone)) {
            return null;
        }

        return $phone;
    }

    /**
     * Get violation type description in Arabic
     */
    private function getViolationTypeDescription(Violation $violation): string
    {
        $descriptions = [
            1 => 'التأخير لغاية 15 دقيقة',
            2 => 'التأخير 15–30 دقيقة',
            3 => 'التأخير 30–60 دقيقة',
            4 => 'التأخير أكثر من 60 دقيقة',
            5 => 'التأثير على سير العمل',
            6 => 'الخروج وترك العمل دون إذن',
        ];

        return $descriptions[$violation->violation_row] ?? ($violation->violation_category ?? 'مخالفة وظيفية');
    }

    /**
     * Get article number based on violation row
     */
    private function getArticleNumber(int $row): string
    {
        $articles = [
            1 => 'المادة الأولى',
            2 => 'المادة الثانية',
            3 => 'المادة الثالثة',
            4 => 'المادة الرابعة',
            5 => 'المادة الخامسة',
            6 => 'المادة السادسة',
        ];

        return $articles[$row] ?? '—';
    }
}