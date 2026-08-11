// =============================================
// Al-Mujtaba Hospital HR — Supabase + Laravel API Client
// Laravel API handles all business logic.
// Supabase remains for direct reads only as fallback.
// =============================================

const SUPABASE_URL  = 'https://vuezoztxocpzooatxuxo.supabase.co';
const SUPABASE_ANON = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InZ1ZXpvenR4b2Nwem9vYXR4dXhvIiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODQzNzE2NjgsImV4cCI6MjA5OTk0NzY2OH0.5w6L9zsSxoYG-GWrn1g1a_y7pXVSXFam8B1di4MsCAo';

// ─── Laravel API base URL ───────────────────────────────────────
// Change this to your VPS URL when deploying
const API_BASE = 'http://localhost:8000/api';

// ─── API helper ─────────────────────────────────────────────────
async function apiGet(path) {
  const res = await fetch(`${API_BASE}${path}`);
  if (!res.ok) throw new Error(`API ${path} → ${res.status}`);
  return res.json();
}

async function apiPost(path, body) {
  const res = await fetch(`${API_BASE}${path}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.message || err.error || `API ${path} → ${res.status}`);
  }
  return res.json();
}

async function apiPut(path, body) {
  const res = await fetch(`${API_BASE}${path}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.message || err.error || `API ${path} → ${res.status}`);
  }
  return res.json();
}

async function apiDelete(path) {
  const res = await fetch(`${API_BASE}${path}`, { method: 'DELETE' });
  if (!res.ok) throw new Error(`API DELETE ${path} → ${res.status}`);
  return res.json();
}

// ─── HR API — all pages use these functions ─────────────────────

const hrApi = {

  // ── DEPARTMENTS ──────────────────────────────────────────────
  async getDepartments(month, year) {
    const m = month || new Date().getMonth() + 1;
    const y = year  || new Date().getFullYear();
    return apiGet(`/departments?month=${m}&year=${y}`);
  },

  async getDepartment(id, month, year) {
    const m = month || new Date().getMonth() + 1;
    const y = year  || new Date().getFullYear();
    return apiGet(`/departments/${id}?month=${m}&year=${y}`);
  },

  async updateDepartment(id, data) {
    return apiPut(`/departments/${id}`, data);
  },

  async sendReminder(depId, month, year) {
    return apiPost(`/departments/${depId}/remind`, { month, year });
  },

  async sendAllReminders(month, year) {
    return apiPost(`/departments/remind-all`, { month, year });
  },

  // ── SCHEDULES ─────────────────────────────────────────────────
  async uploadSchedule(depId, depName, month, year, employees) {
    return apiPost('/schedules/upload', {
      department_id:   depId,
      department_name: depName,
      month, year, employees,
    });
  },

  async getSchedule(depId, month, year) {
    return apiGet(`/schedules/${depId}/${month}/${year}`);
  },

  // ── EMPLOYEES ─────────────────────────────────────────────────
  async getEmployees(params = {}) {
    const q = new URLSearchParams(params).toString();
    return apiGet(`/employees${q ? '?' + q : ''}`);
  },

  async getEmployee(employeeId) {
    return apiGet(`/employees/${employeeId}`);
  },

  async updateEmployee(employeeId, data) {
    return apiPut(`/employees/${employeeId}`, data);
  },

  // ── ATTENDANCE ────────────────────────────────────────────────
  async getAttendance(employeeId, month, year) {
    return apiGet(`/attendance/${employeeId}/${month}/${year}`);
  },

  async uploadFingerprints(month, year, punches) {
    return apiPost('/attendance/upload-fingerprints', { month, year, punches });
  },

  async uploadLeaves(month, year, leaves) {
    return apiPost('/attendance/upload-leaves', { month, year, leaves });
  },

  // ── SHIFT EXCEPTIONS ─────────────────────────────────────────
  async getException(employeeId, month, year) {
    return apiGet(`/exceptions/${employeeId}/${month}/${year}`);
  },

  async getExceptions(month, year, departmentId) {
    let path = `/exceptions?month=${month}&year=${year}`;
    if (departmentId) path += `&department_id=${departmentId}`;
    return apiGet(path);
  },

  async previewException(employeeId, month, year, exceptionHours) {
    return apiPost('/exceptions', {
      employee_id: employeeId,
      month, year,
      exception_hours: exceptionHours,
      preview_only: true,
    });
  },

  async saveException(employeeId, month, year, exceptionHours, reason) {
    return apiPost('/exceptions', {
      employee_id: employeeId,
      month, year,
      exception_hours: exceptionHours,
      reason,
    });
  },

  async deleteException(id) {
    return apiDelete(`/exceptions/${id}`);
  },

  // ── DUTY CARRYOVER ────────────────────────────────────────────
  async getCarryover(employeeId, month, year) {
    return apiGet(`/carryover/${employeeId}/${month}/${year}`);
  },

  async saveCarryover(employeeId, fromMonth, fromYear, surplusShifts, appliedMonth, appliedYear) {
    return apiPost('/carryover', {
      employee_id:    employeeId,
      from_month:     fromMonth,
      from_year:      fromYear,
      surplus_shifts: surplusShifts,
      applied_month:  appliedMonth,
      applied_year:   appliedYear,
    });
  },

  // ── LEAVES ───────────────────────────────────────────────────
  async getLeaves(employeeId, month, year) {
    let path = `/leaves/${employeeId}`;
    if (month && year) path += `?month=${month}&year=${year}`;
    return apiGet(path);
  },

  // ── VIOLATIONS ────────────────────────────────────────────────
  async getViolations(employeeId, month, year) {
    let path = `/violations/${employeeId}`;
    if (month && year) path += `?month=${month}&year=${year}`;
    return apiGet(path);
  },

  async saveViolation(data) {
    return apiPost('/violations', data);
  },

  // ── DISCIPLINARY ──────────────────────────────────────────────
  async getDisciplinary(employeeId) {
    return apiGet(`/disciplinary/${employeeId}`);
  },

  async saveDisciplinary(data) {
    return apiPost('/disciplinary', data);
  },

  // ── OVERTIME ─────────────────────────────────────────────────
  async getOvertime(employeeId, month, year) {
    return apiGet(`/overtime/${employeeId}/${month}/${year}`);
  },

  // ── DOCTOR CONTRACTS ─────────────────────────────────────────
  async getContracts(params = {}) {
    const q = new URLSearchParams(params).toString();
    return apiGet(`/contracts${q ? '?' + q : ''}`);
  },

  async getContractSummary(month, year) {
    return apiGet(`/contracts/summary/${month}/${year}`);
  },

  async getEmployeeContracts(employeeId) {
    return apiGet(`/contracts/${employeeId}`);
  },

  async saveContract(data) {
    return data.id ? apiPut(`/contracts/${data.id}`, data) : apiPost('/contracts', data);
  },

  async deleteContract(id) {
    return apiDelete(`/contracts/${id}`);
  },

  // ── CHEMO MIXING DUTY ─────────────────────────────────────────
  async getChemoDuty(month, year, departmentId) {
    let path = `/chemo-duty?month=${month}&year=${year}`;
    if (departmentId) path += `&department_id=${departmentId}`;
    return apiGet(path);
  },

  async saveChemoDuty(data) {
    return apiPost('/chemo-duty', data);
  },

  async autoDetectChemoDuty(depId, month, year) {
    return apiPost(`/chemo-duty/auto-detect/${depId}/${month}/${year}`, {});
  },

  async confirmChemoDuty(id, confirmedBy) {
    return apiPost(`/chemo-duty/${id}/confirm`, { confirmed_by: confirmedBy });
  },

  async sendChemoDutyEmail(id) {
    return apiPost(`/chemo-duty/${id}/send-email`, {});
  },

  async sendAllChemoDutyEmails(month, year) {
    return apiPost(`/chemo-duty/send-all-emails/${month}/${year}`, {});
  },

  // ── REPORTS ──────────────────────────────────────────────────
  async getEmployeeReport(employeeId, month, year) {
    return apiGet(`/reports/employee/${employeeId}/${month}/${year}`);
  },

  async getDepartmentReport(depId, month, year) {
    return apiGet(`/reports/department/${depId}/${month}/${year}`);
  },

  async getKpi(month, year) {
    return apiGet(`/reports/kpi/${month}/${year}`);
  },
};

// ─── Supabase (still used for direct reads in legacy code) ───────
const _SB_CDNS = [
  'https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2/dist/umd/supabase.js',
  'https://unpkg.com/@supabase/supabase-js@2/dist/umd/supabase.js',
];

let _sbResolve;
let sb = new Promise(res => { _sbResolve = res; });

(function loadSupabase(cdns, i) {
  if (i >= cdns.length) { _sbResolve(null); return; }
  const s = document.createElement('script');
  s.src = cdns[i];
  s.onload = function () {
    if (typeof supabase === 'undefined' || typeof supabase.createClient !== 'function') {
      loadSupabase(cdns, i + 1); return;
    }
    const client = supabase.createClient(SUPABASE_URL, SUPABASE_ANON);
    sb = client;
    _sbResolve(client);
  };
  s.onerror = function () { loadSupabase(cdns, i + 1); };
  document.head.appendChild(s);
})(_SB_CDNS, 0);

async function getSb() {
  const client = await sb;
  if (!client) throw new Error('Supabase client failed to load');
  return client;
}

// ─── SHIFT CODE DEFINITIONS ─────────────────────────────────────
const SHIFT_CODES = {
  'A1': { start:'08:00', end:'16:00', hours:8  },
  'A2': { start:'07:00', end:'15:00', hours:8  },
  'K':  { start:'13:00', end:'21:00', hours:8  },
  'k':  { start:'13:00', end:'21:00', hours:8  },
  'N':  { start:'19:00', end:'07:00', hours:12, crosses_midnight:true },
  'D':  { start:'07:00', end:'19:00', hours:12 },
  'M1': { start:'08:00', end:'20:00', hours:12 },
  'M2': { start:'20:00', end:'08:00', hours:12, crosses_midnight:true },
  'M5': { start:'08:00', end:'14:00', hours:6  },
  'N1': { start:'21:00', end:'07:00', hours:10, crosses_midnight:true },
  'N2': { start:'22:00', end:'06:00', hours:8,  crosses_midnight:true },
  'P4': { start:'08:00', end:'12:00', hours:4  },
  'L':  { start:'06:00', end:'18:00', hours:12 },
  'L1': { start:'06:00', end:'14:00', hours:8  },
  'N3': { start:'18:00', end:'06:00', hours:12, crosses_midnight:true },
  'N4': { start:'20:00', end:'08:00', hours:12, crosses_midnight:true },
};

// ─── VIOLATION TABLE ────────────────────────────────────────────
const VIOLATION_TABLE = [
  { row:1, desc:'التأخير لغاية 15 دقيقة',           penalties:['تنبيه شفوي','إنذار كتابي','خصم ربع يوم','خصم نصف يوم'] },
  { row:2, desc:'التأخير 15–30 دقيقة',              penalties:['تنبيه+خصم مدة التأخير','خصم ربع يوم','خصم نصف يوم','خصم يوم'] },
  { row:3, desc:'التأخير 30–60 دقيقة',              penalties:['تنبيه+خصم مدة التأخير','خصم نصف يوم','خصم يوم','إنذار نهائي+خصم يومان'] },
  { row:4, desc:'التأخير أكثر من 60 دقيقة',         penalties:['إنذار+خصم مدة التأخير','خصم يوم','إنذار نهائي+خصم 3 أيام','خصم 5 أيام+توصية إنهاء خدمة'] },
  { row:5, desc:'التأثير على سير العمل',             penalties:['خصم يوم','خصم يومان','خصم 3 أيام','خصم 4 أيام'] },
  { row:6, desc:'الخروج وترك العمل دون إذن',        penalties:['خصم يوم','خصم يومان','إنذار نهائي+خصم 3 أيام','خصم 5 أيام+توصية إنهاء خدمة'] },
];

// ─── UTILITIES ──────────────────────────────────────────────────
const MONTHS_AR = ['يناير','فبراير','مارس','أبريل','مايو','يونيو',
                   'يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];

function arabicMonth(m)  { return MONTHS_AR[(m-1)] || m; }
function formatDate(d) {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('ar-IQ', { year:'numeric', month:'short', day:'numeric' });
}
function minutesToHHMM(min) {
  if (!min || min <= 0) return '0:00';
  const h = Math.floor(Math.abs(min) / 60);
  const m = Math.abs(min % 60);
  return `${h}:${String(m).padStart(2,'0')}`;
}
const mHHMM = minutesToHHMM;
const fDate  = formatDate;

function toast(msg, type = 'success') {
  const el = document.getElementById('toast');
  if (!el) return;
  el.textContent = msg;
  el.className = `toast toast-${type} on`;
  setTimeout(() => el.classList.remove('on'), 2800);
}

function showToast(msg, type = 'success') { toast(msg, type); }

function calcOvertimePayout(overtimeMinutes, basicSalary) {
  const overtimeHours = overtimeMinutes / 60;
  const overtimeDays  = overtimeHours / 8;
  const dailyRate     = basicSalary / 30;
  return Math.round(overtimeDays * dailyRate);
}

// ─── HR_WORKSPACE BRIDGE (kept for legacy pages) ─────────────────
const WORKSPACE_ID = 'default';
let _wsCache = null;
let _wsCacheTime = 0;
const WS_CACHE_TTL = 30000;

function cloudReviver(key, value) {
  if (value && typeof value === 'object' && value.__type === 'Date') return new Date(value.v);
  if (value && typeof value === 'object' && value.__type === 'Map')  return new Map(value.v);
  return value;
}

async function getWorkspace() {
  const now = Date.now();
  if (_wsCache && (now - _wsCacheTime) < WS_CACHE_TTL) return _wsCache;
  try {
    const client = await sb;
    if (!client) { console.warn('hr_workspace: Supabase client not available'); return null; }
    const { data, error } = await client.from('hr_workspace').select('*').eq('id', WORKSPACE_ID).maybeSingle();
    if (error) { console.warn('hr_workspace error:', error.message); return null; }
    if (!data)  { console.warn('hr_workspace: no row found'); return null; }
    let snap = null;
    if (data.snapshot) {
      snap = typeof data.snapshot === 'string'
        ? JSON.parse(data.snapshot, cloudReviver)
        : JSON.parse(JSON.stringify(data.snapshot), cloudReviver);
    } else if (data.results) {
      snap = JSON.parse(JSON.stringify(data), cloudReviver);
    }
    if (!snap || !snap.results || !snap.results.length) { console.warn('hr_workspace: empty snapshot'); return null; }
    console.log('hr_workspace loaded:', snap.results.length, 'employees');
    _wsCache = snap;
    _wsCacheTime = now;
    return snap;
  } catch(e) {
    console.warn('hr_workspace read failed:', e.message);
    return null;
  }
}

function setCachedWorkspace(snap) {
  _wsCache = snap;
  _wsCacheTime = Date.now();
}

async function getWsEmployee(deviceId) {
  const snap = await getWorkspace();
  if (!snap || !snap.results) return null;
  return snap.results.find(r => String(r.employee?.deviceId) === String(deviceId)) || null;
}

function detectShiftTypeFromResult(r) {
  const days  = r.employee?.days || {};
  const codes = Object.values(days).filter(Boolean).map(c => String(c).toUpperCase());
  if (codes.some(c => ['D','N','M1','M2','N1','N2','N3','N4','L','L1'].includes(c))) return '12hr';
  if (codes.some(c => ['M5','P4','P6'].includes(c))) return '6hr';
  return '8hr';
}

async function syncWorkspaceEmployeesToDb() {
  const snap = await getWorkspace();
  if (!snap?.results?.length) return { synced: 0, error: 'no workspace data' };
  const client = await getSb();
  const { data: deps } = await client.from('departments').select('id,name');
  const rows = snap.results.map(r => {
    const emp   = r.employee || {};
    const empId = String(emp.deviceId || emp.sn || '').trim();
    if (!empId) return null;
    const deptName = (emp.deptRaw || emp.deptNorm || '').trim();
    let deptId = null;
    if (deptName) {
      const match = (deps||[]).find(d => d.name === deptName || d.name.includes(deptName) || deptName.includes(d.name));
      if (match) deptId = match.id;
    }
    return { employee_id: empId, name: (emp.name||'—').trim(), department_id: deptId,
             shift_type: detectShiftTypeFromResult(r), nationality: emp.nat||null, full_or_part: emp.fullOrPart||'full' };
  }).filter(Boolean).filter(e => e.name !== '—');
  if (!rows.length) return { synced: 0, error: 'No valid rows' };
  let synced = 0;
  for (let i = 0; i < rows.length; i += 100) {
    const { error } = await client.from('employees').upsert(rows.slice(i, i+100), { onConflict: 'employee_id' });
    if (error) return { synced, error: error.message };
    synced += rows.slice(i, i+100).length;
  }
  return { synced, error: null };
}
