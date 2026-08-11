/* Al-Mujtaba HR — shared application shell.
   Injects the same fixed sidebar into every page and owns the AR/EN switch, so
   navigation and language never drift between pages. Pages react to a language
   change by listening for the `hr:lang` event on `document`. */
(function () {
  'use strict';

  var NAV = [
    { sect: { ar: 'الرئيسية', en: 'Home' } },
    { href: 'index.html', icon: '🏠', ar: 'لوحة التحكم', en: 'Dashboard' },
    { sect: { ar: 'الإدارة', en: 'Administration' } },
    { href: 'departments.html', icon: '🏢', ar: 'الأقسام', en: 'Departments' },
    { href: 'employees.html', icon: '👥', ar: 'الموظفون', en: 'Employees' },
    { href: 'exceptions.html', icon: '🏥', ar: 'الاستثناءات', en: 'Exceptions' },
    { sect: { ar: 'العمليات', en: 'Operations' } },
    { href: 'Al-Mujtaba_HR_Attendance_System.html', icon: '📊', ar: 'التقارير والرفع', en: 'Reports & Upload' },
    { href: 'daily-attendance.html', icon: '📅', ar: 'الموقف اليومي', en: 'Daily Status' },
    { href: 'disciplinary-action.html', icon: '⚖️', ar: 'الإجراءات التأديبية', en: 'Disciplinary' },
    { href: 'forms.html', icon: '📋', ar: 'النماذج الرسمية', en: 'Official Forms' }
  ];

  var TXT = {
    hospital: { ar: 'مستشفى الإمام الحسن المجتبى (ع)', en: 'Imam Al-Hassan Al-Mujtaba Hospital' },
    system:   { ar: 'نظام الموارد البشرية', en: 'HR System' },
    foot:     { ar: 'نظام HR © 2026', en: 'HR System © 2026' },
    other:    { ar: 'English', en: 'العربية' }
  };

  function currentFile() {
    var path = window.location.pathname;
    var file = decodeURIComponent(path.substring(path.lastIndexOf('/') + 1));
    return file || 'index.html';
  }

  function storedLang() {
    try { return localStorage.getItem('hr-lang') === 'en' ? 'en' : 'ar'; } catch (e) { return 'ar'; }
  }

  var shell = {
    lang: storedLang(),

    /* Applies the language to the document + sidebar and notifies the page. */
    setLang: function (lang, silent) {
      shell.lang = lang === 'en' ? 'en' : 'ar';
      try { localStorage.setItem('hr-lang', shell.lang); } catch (e) { /* private mode */ }
      var root = document.documentElement;
      root.setAttribute('lang', shell.lang);
      root.setAttribute('dir', shell.lang === 'en' ? 'ltr' : 'rtl');
      document.body.classList.toggle('en', shell.lang === 'en');
      shell.render();
      if (!silent) document.dispatchEvent(new CustomEvent('hr:lang', { detail: { lang: shell.lang } }));
    },

    toggleLang: function () { shell.setLang(shell.lang === 'ar' ? 'en' : 'ar'); },

    render: function () {
      var host = document.getElementById('hr-sidebar');
      if (!host) return;
      var lang = shell.lang;
      var here = currentFile();
      var nav = NAV.map(function (item) {
        if (item.sect) return '<div class="hr-nav-sect">' + item.sect[lang] + '</div>';
        var active = item.href === here ? ' active' : '';
        return '<a class="hr-nav-item' + active + '" href="' + item.href + '">' +
               '<span class="hr-nav-icon">' + item.icon + '</span>' +
               '<span>' + item[lang] + '</span></a>';
      }).join('');

      host.innerHTML =
        '<div class="hr-sidebar-logo">' +
          '<img src="logo.png" alt="" onerror="this.style.display=\'none\'">' +
          '<div><div class="hr-logo-name">' + TXT.hospital[lang] + '</div>' +
          '<div class="hr-logo-sys">' + TXT.system[lang] + '</div></div>' +
        '</div>' +
        '<button type="button" class="hr-lang-btn" id="hr-lang-btn">🌐 <span>' + TXT.other[lang] + '</span></button>' +
        '<nav>' + nav + '</nav>' +
        '<div class="hr-sidebar-foot">' + TXT.foot[lang] + '</div>';

      document.getElementById('hr-lang-btn').addEventListener('click', shell.toggleLang);
    },

    mount: function () {
      if (!document.getElementById('hr-sidebar')) {
        var aside = document.createElement('aside');
        aside.id = 'hr-sidebar';
        aside.className = 'hr-sidebar';
        document.body.insertBefore(aside, document.body.firstChild);
      }
      shell.setLang(shell.lang, true);
      document.dispatchEvent(new CustomEvent('hr:shell-ready', { detail: { lang: shell.lang } }));
    }
  };

  window.hrShell = shell;

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', shell.mount);
  else shell.mount();
})();
