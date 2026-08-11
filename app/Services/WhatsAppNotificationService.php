<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Violation;
use App\Models\WhatsAppLog;
use App\Support\ViolationPolicy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends the Arabic violation / disciplinary notifications over the WhatsApp
 * Business Platform and records every attempt in the HR audit log.
 *
 * With WHATSAPP_ENABLED=false the message is still rendered and logged with the
 * status "dry_run", so HR can review the exact text before the API is wired up.
 */
class WhatsAppNotificationService
{
    private string $apiUrl;
    private ?string $apiKey;
    private ?string $senderNumber;
    private bool $enabled;
    private int $timeout;

    public function __construct()
    {
        $this->apiUrl       = (string) config('whatsapp.api_url', 'https://graph.facebook.com/v18.0');
        $this->apiKey       = config('whatsapp.api_key');
        $this->senderNumber = config('whatsapp.sender_number');
        $this->enabled      = (bool) config('whatsapp.enabled', false);
        $this->timeout      = (int) config('whatsapp.timeout', 30);
    }

    public function sendViolationNotification(Employee $employee, Violation $violation): array
    {
        return $this->deliver(
            $employee,
            $this->buildViolationMessage($employee, $violation),
            'violation',
            Violation::class,
            $violation->id
        );
    }

    public function sendDisciplinaryNotification(Employee $employee, array $disciplinaryAction): array
    {
        return $this->deliver(
            $employee,
            $this->buildDisciplinaryMessage($employee, $disciplinaryAction),
            'disciplinary',
            $disciplinaryAction['type'] ?? null,
            $disciplinaryAction['id'] ?? null
        );
    }

    /**
     * Notify every employee in a set of violations.
     *
     * @param  iterable<Violation>  $violations
     */
    public function sendDailyViolationNotifications(iterable $violations): array
    {
        $results = [];

        foreach ($violations as $violation) {
            $employee = $violation->employee ?? Employee::where('employee_id', $violation->employee_id)->first();

            if (!$employee) {
                $results[] = [
                    'success'     => false,
                    'employee_id' => $violation->employee_id,
                    'message'     => 'Employee not found',
                ];
                continue;
            }

            $result = $this->sendViolationNotification($employee, $violation);

            if ($result['success']) {
                $violation->forceFill([
                    'status'      => 'notified',
                    'notified_at' => now(),
                ])->save();
            }

            $results[] = $result + ['employee_id' => $employee->employee_id];
        }

        return [
            'total'   => count($results),
            'sent'    => count(array_filter($results, fn ($r) => $r['success'])),
            'failed'  => count(array_filter($results, fn ($r) => !$r['success'])),
            'results' => $results,
        ];
    }

    /** Renders the message an employee would receive without contacting WhatsApp. */
    public function previewViolationMessage(Employee $employee, Violation $violation): array
    {
        return [
            'employee_id' => $employee->employee_id,
            'phone'       => $this->formatPhoneNumber($employee->phone_number),
            'message'     => $this->buildViolationMessage($employee, $violation),
        ];
    }

    private function deliver(
        Employee $employee,
        string $message,
        string $kind,
        ?string $referenceType = null,
        ?int $referenceId = null
    ): array {
        $phoneNumber = $this->formatPhoneNumber($employee->phone_number);

        $log = WhatsAppLog::create([
            'employee_id'    => $employee->employee_id,
            'phone_number'   => $phoneNumber,
            'kind'           => $kind,
            'reference_type' => $referenceType,
            'reference_id'   => $referenceId,
            'message'        => $message,
            'status'         => 'queued',
        ]);

        if (!$phoneNumber) {
            $log->update(['status' => 'failed', 'error' => 'رقم الهاتف غير متوفر أو غير صالح']);

            return [
                'success' => false,
                'message' => 'Employee phone number not available or invalid',
                'log_id'  => $log->id,
            ];
        }

        if (!$this->enabled || !$this->apiKey || !$this->senderNumber) {
            $log->update(['status' => 'dry_run', 'sent_at' => now()]);
            Log::info('WhatsApp dry run', ['employee_id' => $employee->employee_id, 'kind' => $kind]);

            return [
                'success'   => true,
                'dry_run'   => true,
                'message'   => 'WhatsApp is disabled — message rendered and logged only',
                'body'      => $message,
                'phone'     => $phoneNumber,
                'log_id'    => $log->id,
            ];
        }

        try {
            $response = $this->sendWhatsAppMessage($phoneNumber, $message);

            $log->update([
                'status'              => 'sent',
                'provider_message_id' => $response['messages'][0]['id'] ?? null,
                'sent_at'             => now(),
            ]);

            return [
                'success'  => true,
                'message'  => 'WhatsApp notification sent successfully',
                'response' => $response,
                'log_id'   => $log->id,
            ];
        } catch (\Exception $e) {
            $log->update(['status' => 'failed', 'error' => $e->getMessage()]);
            Log::error('WhatsApp notification failed', [
                'employee_id' => $employee->employee_id,
                'error'       => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to send WhatsApp notification: ' . $e->getMessage(),
                'log_id'  => $log->id,
            ];
        }
    }

    private function buildViolationMessage(Employee $employee, Violation $violation): string
    {
        $violationDate = \Carbon\Carbon::parse($violation->incident_date)->format('Y/m/d');
        $article       = ViolationPolicy::article($violation->violation_row);
        $type          = ViolationPolicy::typeLabel($violation->violation_type ?? '', $violation->violation_row);
        $details       = $violation->notes ?: ($violation->violation_category ?? '—');

        return <<<MESSAGE
        مرحباً {$employee->name}،
        نود إعلامكم بأنه تم تسجيل مخالفة وظيفية بحقكم وفقاً لسجلات الحضور واللوائح المعتمدة لدى المؤسسة.
        تفاصيل المخالفة:
        • الرقم الوظيفي: {$employee->employee_id}
        • نوع المخالفة: {$type}
        • تاريخ المخالفة: {$violationDate}
        • تفاصيل المخالفة: {$details}
        • المادة: {$article}
        الجزاء المترتب:
        {$violation->penalty}
        تم تسجيل المخالفة والجزاء في نظام الموارد البشرية وفقاً للائحة المخالفات والجزاءات المعتمدة.
        في حال كان لديكم اعتراض أو ملاحظات على المخالفة، يرجى اتباع إجراءات الاعتراض المعتمدة لدى إدارة الموارد البشرية.
        مع كامل الاحترام،
        فريق الموارد البشرية
        MESSAGE;
    }

    private function buildDisciplinaryMessage(Employee $employee, array $action): string
    {
        $severityMap = [
            'low'    => 'منخفضة',
            'medium' => 'متوسطة',
            'high'   => 'عالية',
        ];
        $severityText = $severityMap[$action['severity'] ?? ''] ?? '—';
        $penalty      = $action['penalty'] ?? 'يتم تحديد الجزاء من قبل إدارة الموارد البشرية';

        return <<<MESSAGE
        مرحباً {$employee->name}،
        نود إعلامكم بأنه تم تسجيل مخالفة انضباطية بحقكم من قبل إدارة الموارد البشرية.
        تفاصيل المخالفة:
        • الرقم الوظيفي: {$employee->employee_id}
        • نوع المخالفة: {$action['action_type']}
        • درجة الخطورة: {$severityText}
        • تفاصيل المخالفة: {$action['note']}
        الجزاء المترتب:
        {$penalty}
        تم تسجيل المخالفة والجزاء في ملفكم الوظيفي وفقاً للائحة المخالفات والجزاءات المعتمدة.
        في حال كان لديكم اعتراض أو ملاحظات، يرجى مراجعة إدارة الموارد البشرية.
        مع كامل الاحترام،
        فريق الموارد البشرية
        MESSAGE;
    }

    private function sendWhatsAppMessage(string $phoneNumber, string $message): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $phoneNumber,
            'type'              => 'text',
            'text'              => ['body' => $message],
        ];

        $response = Http::withToken($this->apiKey)
            ->timeout($this->timeout)
            ->post("{$this->apiUrl}/{$this->senderNumber}/messages", $payload);

        if (!$response->successful()) {
            throw new \Exception('WhatsApp API error: ' . $response->body());
        }

        return $response->json();
    }

    /** Iraqi mobile numbers in any local notation → +9647XXXXXXXXX. */
    private function formatPhoneNumber(?string $phone): ?string
    {
        if (!$phone) return null;

        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '00964')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '964')) {
            $digits = substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        $number = '+' . config('whatsapp.default_country_code', '964') . $digits;

        return preg_match('/^\+9647\d{9}$/', $number) ? $number : null;
    }
}
