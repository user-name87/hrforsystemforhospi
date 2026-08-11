<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\DepartmentController;
use App\Http\Controllers\API\EmployeeController;
use App\Http\Controllers\API\AttendanceController;
use App\Http\Controllers\API\ScheduleController;
use App\Http\Controllers\API\LeaveController;
use App\Http\Controllers\API\OvertimeController;
use App\Http\Controllers\API\ExceptionController;
use App\Http\Controllers\API\ViolationController;
use App\Http\Controllers\API\ReportController;
use App\Http\Controllers\API\DutyCarryoverController;

/*
|--------------------------------------------------------------------------
| API Routes — Al-Mujtaba HR System
|--------------------------------------------------------------------------
*/

// ── DEPARTMENTS ──────────────────────────────────────────────────────────
Route::get   ('departments',                    [DepartmentController::class, 'index']);
Route::post  ('departments',                    [DepartmentController::class, 'store']);
Route::get   ('departments/{id}',               [DepartmentController::class, 'show']);
Route::put   ('departments/{id}',               [DepartmentController::class, 'update']);
Route::delete('departments/{id}',               [DepartmentController::class, 'destroy']);
Route::post  ('departments/{id}/remind',        [DepartmentController::class, 'sendReminder']);
Route::post  ('departments/{id}/remind-all',    [DepartmentController::class, 'sendAllReminders']);

// ── SCHEDULES ─────────────────────────────────────────────────────────────
Route::post  ('schedules/upload',               [ScheduleController::class, 'upload']);
Route::get   ('schedules/{depId}/{month}/{year}',[ScheduleController::class, 'getForDepartment']);
Route::delete('schedules/{depId}/{month}/{year}',[ScheduleController::class, 'delete']);

// ── EMPLOYEES ─────────────────────────────────────────────────────────────
Route::get   ('employees',                      [EmployeeController::class, 'index']);
Route::post  ('employees',                      [EmployeeController::class, 'store']);
Route::get   ('employees/{employeeId}',         [EmployeeController::class, 'show']);
Route::put   ('employees/{employeeId}',         [EmployeeController::class, 'update']);
Route::delete('employees/{employeeId}',         [EmployeeController::class, 'destroy']);

// ── ATTENDANCE ────────────────────────────────────────────────────────────
// Upload fingerprints (punch data from Excel)
Route::post  ('attendance/upload-fingerprints', [AttendanceController::class, 'uploadFingerprints']);
// Upload leaves (from Excel — includes approval status)
Route::post  ('attendance/upload-leaves',       [AttendanceController::class, 'uploadLeaves']);
// Full computed attendance report for one employee / month
Route::get   ('attendance/{employeeId}/{month}/{year}', [AttendanceController::class, 'getEmployeeAttendance']);
// Department-wide attendance for a month
Route::get   ('attendance/department/{depId}/{month}/{year}', [AttendanceController::class, 'getDepartmentAttendance']);

// ── SHIFT EXCEPTIONS (Chemo / IV Mixing etc.) ────────────────────────────
Route::get   ('exceptions',                     [ExceptionController::class, 'index']);
Route::post  ('exceptions',                     [ExceptionController::class, 'store']);
Route::put   ('exceptions/{id}',                [ExceptionController::class, 'update']);
Route::delete('exceptions/{id}',                [ExceptionController::class, 'destroy']);
// Quick lookup: does this employee have an exception this month?
Route::get   ('exceptions/{employeeId}/{month}/{year}', [ExceptionController::class, 'getForEmployee']);

// ── DUTY CARRYOVER ────────────────────────────────────────────────────────
Route::get   ('carryover',                      [DutyCarryoverController::class, 'index']);
Route::post  ('carryover',                      [DutyCarryoverController::class, 'store']);
Route::put   ('carryover/{id}',                 [DutyCarryoverController::class, 'update']);
Route::delete('carryover/{id}',                 [DutyCarryoverController::class, 'destroy']);
Route::get   ('carryover/{employeeId}/{month}/{year}', [DutyCarryoverController::class, 'getForEmployee']);

// ── LEAVES ────────────────────────────────────────────────────────────────
Route::get   ('leaves',                         [LeaveController::class, 'index']);
Route::get   ('leaves/{employeeId}',            [LeaveController::class, 'getForEmployee']);
Route::post  ('leaves',                         [LeaveController::class, 'store']);
Route::put   ('leaves/{id}/approve',            [LeaveController::class, 'approve']);
Route::put   ('leaves/{id}/reject',             [LeaveController::class, 'reject']);
Route::delete('leaves/{id}',                    [LeaveController::class, 'destroy']);

// ── VIOLATIONS ────────────────────────────────────────────────────────────
Route::get   ('violations',                     [ViolationController::class, 'index']);
Route::get   ('violations/{employeeId}',        [ViolationController::class, 'getForEmployee']);
Route::post  ('violations',                     [ViolationController::class, 'store']);
Route::delete('violations/{id}',                [ViolationController::class, 'destroy']);

// ── DISCIPLINARY ACTIONS ──────────────────────────────────────────────────
Route::get   ('disciplinary/{employeeId}',      [ViolationController::class, 'getDisciplinary']);
Route::post  ('disciplinary',                   [ViolationController::class, 'storeDisciplinary']);

// ── OVERTIME ──────────────────────────────────────────────────────────────
Route::get   ('overtime/{employeeId}/{month}/{year}', [OvertimeController::class, 'calculate']);
Route::post  ('overtime/manual',                [OvertimeController::class, 'addManual']);

// ── DOCTOR CONTRACTS (residents & specialists) ────────────────────────────
Route::get   ('contracts',                                     [\App\Http\Controllers\API\DoctorContractController::class, 'index']);
Route::post  ('contracts',                                     [\App\Http\Controllers\API\DoctorContractController::class, 'store']);
Route::get   ('contracts/summary/{month}/{year}',              [\App\Http\Controllers\API\DoctorContractController::class, 'monthlySummary']);
Route::get   ('contracts/{employeeId}',                        [\App\Http\Controllers\API\DoctorContractController::class, 'getForEmployee']);
Route::put   ('contracts/{id}',                                [\App\Http\Controllers\API\DoctorContractController::class, 'update']);
Route::delete('contracts/{id}',                                [\App\Http\Controllers\API\DoctorContractController::class, 'destroy']);

// ── CHEMO MIXING DUTY ─────────────────────────────────────────────────────
Route::get   ('chemo-duty',                                    [\App\Http\Controllers\API\ChemoMixingDutyController::class, 'index']);
Route::post  ('chemo-duty',                                    [\App\Http\Controllers\API\ChemoMixingDutyController::class, 'store']);
Route::get   ('chemo-duty/{employeeId}/{month}/{year}',        [\App\Http\Controllers\API\ChemoMixingDutyController::class, 'getForEmployee']);
Route::post  ('chemo-duty/auto-detect/{depId}/{month}/{year}', [\App\Http\Controllers\API\ChemoMixingDutyController::class, 'autoDetect']);
Route::post  ('chemo-duty/{id}/confirm',                       [\App\Http\Controllers\API\ChemoMixingDutyController::class, 'confirm']);
Route::post  ('chemo-duty/{id}/send-email',                    [\App\Http\Controllers\API\ChemoMixingDutyController::class, 'sendConfirmationEmail']);
Route::post  ('chemo-duty/send-all-emails/{month}/{year}',     [\App\Http\Controllers\API\ChemoMixingDutyController::class, 'sendAllEmails']);
Route::delete('chemo-duty/{id}',                               [\App\Http\Controllers\API\ChemoMixingDutyController::class, 'destroy']);

// ── REPORTS ───────────────────────────────────────────────────────────────
// Full monthly report for one employee (the big combined report)
Route::get   ('reports/employee/{employeeId}/{month}/{year}', [ReportController::class, 'employeeMonthly']);
// Department-wide summary report
Route::get   ('reports/department/{depId}/{month}/{year}',    [ReportController::class, 'departmentMonthly']);
// Dashboard KPIs
Route::get   ('reports/kpi/{month}/{year}',                   [ReportController::class, 'kpi']);
