/* Lucas Chấm Công — trang nhân viên tự điền ca (lucas.vn/cham-cong) */
(function () {
  'use strict';
  var API = (window.LCC && LCC.api) || '/wp-json/lcc/v1/';
  var root = document.getElementById('lcc-app');
  if (!root) return;

  // Phải khớp LCC_TYPES trong plugin và cách tính trong tool lương
  var SHIFT = {
    std:   { label: 'Ca 9h – 18h',  short: '9–18h',   cong: 1,   cls: 'std' },
    pm:    { label: 'Ca 12h – 20h', short: '12–20h',  cong: 1,   cls: 'pm' },
    full:  { label: 'Full 9h – 20h (tăng ca 2 tiếng)', short: '9–20h', cong: 1, cls: 'full' },
    half:  { label: 'Nửa buổi (sáng hoặc chiều)', short: '½ buổi', cong: 0.5, cls: 'half' },
    leave: { label: 'Nghỉ phép', short: 'Phép', cong: 0, cls: 'leave' },
    off:   { label: 'Nghỉ', short: 'Nghỉ', cong: 0, cls: 'off' }
  };
  var DOW = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];

  var S = { token: null, name: '', role: '', month: '', data: null };
  try { S.token = localStorage.getItem('lcc_token'); } catch (e) {}

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function money(n) { return Math.round(n || 0).toLocaleString('vi-VN') + '₫'; }
  function ym(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2); }
  function api(path, body) {
    var opt = { method: body ? 'POST' : 'GET', headers: { 'Content-Type': 'application/json' } };
    if (S.token) opt.headers['X-LCC-Token'] = S.token;
    if (body) opt.body = JSON.stringify(body);
    // Web để permalink mặc định thì API là ".../?rest_route=/lcc/v1/" -> tham số nối bằng & chứ không phải ?
    var url = API + (API.indexOf('?') >= 0 ? path.replace('?', '&') : path);
    return fetch(url, opt).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (r.status === 401 && S.token) { logout(); }
        if (!r.ok) throw new Error(j.error || j.message || ('Lỗi ' + r.status));
        return j;
      });
    });
  }
  function saveTok(t) { S.token = t; try { t ? localStorage.setItem('lcc_token', t) : localStorage.removeItem('lcc_token'); } catch (e) {} }
  function logout() { saveTok(null); S.data = null; renderLogin(); }

  /* ---------------------------------------------------------------- đăng nhập */
  function renderLogin(msg) {
    root.innerHTML = '<div class="lcc-card lcc-login"><h2>Chấm công Lucas Combo</h2>' +
      '<p class="lcc-mut">Chọn tên của bạn và nhập mã PIN.</p>' +
      '<label>Tên</label><select id="lcc-code"><option value="">Đang tải…</option></select>' +
      '<label>Mã PIN</label><input id="lcc-pin" type="password" inputmode="numeric" autocomplete="current-password" placeholder="••••">' +
      '<button class="lcc-btn" id="lcc-go">Đăng nhập</button><div class="lcc-err" id="lcc-err">' + esc(msg || '') + '</div></div>';
    api('staff').then(function (list) {
      var sel = document.getElementById('lcc-code');
      sel.innerHTML = '<option value="">— chọn tên —</option>' + list.map(function (e) {
        return '<option value="' + esc(e.code) + '">' + esc(e.name) + '</option>'; }).join('');
      try { var last = localStorage.getItem('lcc_last'); if (last) sel.value = last; } catch (e) {}
    }).catch(function (e) { document.getElementById('lcc-err').textContent = e.message; });
    function go() {
      var code = document.getElementById('lcc-code').value, pin = document.getElementById('lcc-pin').value;
      if (!code || !pin) { document.getElementById('lcc-err').textContent = 'Chọn tên và nhập PIN.'; return; }
      api('login', { code: code, pin: pin }).then(function (r) {
        saveTok(r.token); try { localStorage.setItem('lcc_last', code); } catch (e) {}
        S.month = ym(new Date()); load();
      }).catch(function (e) { document.getElementById('lcc-err').textContent = e.message; });
    }
    document.getElementById('lcc-go').onclick = go;
    document.getElementById('lcc-pin').onkeydown = function (e) { if (e.key === 'Enter') go(); };
  }

  /* ---------------------------------------------------------------- tháng */
  function load() {
    root.innerHTML = '<div class="lcc-loading">Đang tải…</div>';
    api('me?month=' + S.month).then(function (d) { S.data = d; S.name = d.emp.name; S.role = d.emp.role; render(); })
      .catch(function (e) { if (S.token) root.innerHTML = '<div class="lcc-card lcc-err">' + esc(e.message) + '</div>'; });
  }
  function shiftMap() { var m = {}; (S.data.shifts || []).forEach(function (s) { m[s.day] = s; }); return m; }
  function days() {
    var p = S.month.split('-'), y = +p[0], mo = +p[1] - 1, n = new Date(y, mo + 1, 0).getDate(), out = [];
    for (var i = 1; i <= n; i++) { var dt = new Date(y, mo, i); out.push({ key: S.month + '-' + ('0' + i).slice(-2), d: i, dow: dt.getDay() }); }
    return out;
  }
  function stats() {
    var m = shiftMap(), st = { cong: 0, full: 0, sun: 0, extra: 0, leave: 0, filled: 0 };
    days().forEach(function (x) {
      var s = m[x.key]; if (!s) return; st.filled++;
      var c = SHIFT[s.type] ? SHIFT[s.type].cong : 0;
      st.cong += c; if (s.type === 'full') st.full++; if (s.type === 'leave') st.leave++;
      if (x.dow === 0 && c > 0) st.sun++;
      st.extra += +s.extra_hours || 0;
    });
    return st;
  }
  function shiftMonth(delta) {
    var p = S.month.split('-'); S.month = ym(new Date(+p[0], +p[1] - 1 + delta, 1)); load();
  }

  function render() {
    var d = S.data, m = shiftMap(), st = stats(), locked = d.locked, today = new Date();
    var todayKey = ym(today) + '-' + ('0' + today.getDate()).slice(-2);
    var label = 'Tháng ' + (+S.month.split('-')[1]) + '/' + S.month.split('-')[0];
    var h = '<div class="lcc-top"><div><b>' + esc(S.name) + '</b><span class="lcc-mut"> · ' +
      (S.role === 'intern' ? 'Thực tập sinh' : 'Nhân viên') + '</span></div><button class="lcc-link" id="lcc-out">Đăng xuất</button></div>';
    h += '<div class="lcc-month"><button class="lcc-nav" data-d="-1">‹</button><b>' + label + '</b><button class="lcc-nav" data-d="1">›</button></div>';
    h += '<div class="lcc-stats"><div><b>' + st.cong + '</b>công</div><div><b>' + st.full + '</b>ngày 9–20h</div>' +
      '<div><b>' + st.sun + '</b>Chủ nhật</div><div><b>' + st.extra + 'h</b>làm thêm</div><div><b>' + st.leave + '</b>nghỉ phép</div></div>';
    if (d.payslip) h += renderSlip(d.payslip);
    if (S.month === ym(today) && !locked) {
      var tp = (d.punches || []).filter(function (p) { return p.day === todayKey; });
      h += '<div class="lcc-today"><div class="lcc-tt"><b>Hôm nay ' + today.getDate() + '/' + (today.getMonth() + 1) + '</b>' +
        (d.on_wifi ? '<span class="lcc-wifi ok">📶 Wifi tiệm</span>' : '<span class="lcc-wifi no">' + (d.wifi_set ? '✕ Không phải wifi tiệm' : 'Chưa cài wifi tiệm') + '</span>') + '</div>' +
        (tp.length ? '<div class="lcc-mut">' + tp.map(function (p) { return 'Vào ' + p.in_t + (p.out_t ? ' → Ra ' + p.out_t : ' → đang trong ca'); }).join(' · ') + '</div>' : '') +
        (d.open_since ? '<button class="lcc-btn out" id="lcc-punch" data-a="out">Ra ca</button>'
                      : '<button class="lcc-btn" id="lcc-punch" data-a="in">Vào ca</button>') +
        (d.on_wifi ? '' : '<div class="lcc-err">Phải bắt wifi tiệm mới chấm công được (4G/5G không tính).</div>') +
        '<div class="lcc-err" id="lcc-perr"></div></div>';
    }
    if (locked) h += '<div class="lcc-note ok">Tháng này đã chốt lương — ca làm bên dưới chỉ để xem.</div>';
    else h += '<div class="lcc-note">Ngày đi làm: bấm <b>Vào ca</b> khi tới, <b>Ra ca</b> khi về (bằng wifi tiệm) — ca được tính theo giờ thật. ' +
      'Ngày nghỉ / nghỉ phép: chạm vào ngày đó để chọn. Quên chấm công thì báo quản lý sửa.</div>';
    h += '<div class="lcc-days">' + days().map(function (x) {
      var s = m[x.key], cfg = s && SHIFT[s.type];
      return '<button class="lcc-day' + (x.dow === 0 ? ' sun' : '') + (x.key === todayKey ? ' today' : '') + '" data-k="' + x.key + '"' + (locked ? ' disabled' : '') + '>' +
        '<span class="lcc-dd">' + DOW[x.dow] + '<b>' + x.d + '</b></span>' +
        (cfg ? '<span class="lcc-tag ' + cfg.cls + '">' + cfg.short + '</span>' : '<span class="lcc-tag empty">chưa điền</span>') +
        (s && +s.extra_hours ? '<span class="lcc-tag extra">+' + (+s.extra_hours) + 'h</span>' : '') +
        (s && s.src === 'punch' ? '<span class="lcc-tag ok">✓ wifi</span>' : '') +
        (s && s.note ? '<span class="lcc-dnote">' + esc(s.note) + '</span>' : '') + '</button>';
    }).join('') + '</div>';
    root.innerHTML = h;
    document.getElementById('lcc-out').onclick = logout;
    root.querySelectorAll('.lcc-nav').forEach(function (b) { b.onclick = function () { shiftMonth(+b.dataset.d); }; });
    root.querySelectorAll('.lcc-day').forEach(function (b) { b.onclick = function () { edit(b.dataset.k); }; });
    var pb = document.getElementById('lcc-punch');
    if (pb) pb.onclick = function () {
      pb.disabled = true;
      api('punch', { action: pb.dataset.a }).then(function () { load(); })
        .catch(function (e) { pb.disabled = false; document.getElementById('lcc-perr').textContent = e.message; });
    };
  }

  function edit(key) {
    var s = shiftMap()[key] || {}, dt = new Date(key + 'T00:00:00');
    if (s.src === 'punch' || s.src === 'admin') {
      alert((s.src === 'punch' ? 'Ngày này đã chấm công wifi' : 'Quản lý đã chốt ngày này') + ':\n' + (s.note || '') +
            '\n\nSai thì báo quản lý sửa.');
      return;
    }
    var cur = s.type || '', extra = +s.extra_hours || 0;
    var sheet = document.createElement('div');
    sheet.className = 'lcc-sheet';
    sheet.innerHTML = '<div class="lcc-panel"><div class="lcc-ph"><b>' + DOW[dt.getDay()] + ', ' + dt.getDate() + '/' + (dt.getMonth() + 1) +
      '</b><button class="lcc-link" data-x>Đóng</button></div>' +
      '<p class="lcc-mut" style="margin:0 0 8px">Ngày đi làm thì bấm Vào ca / Ra ca bằng wifi tiệm. Ở đây chỉ báo nghỉ:</p>' +
      ['leave', 'off'].map(function (k) {
        return '<button class="lcc-opt ' + SHIFT[k].cls + (k === cur ? ' on' : '') + '" data-t="' + k + '">' + SHIFT[k].label + '</button>'; }).join('') +
      '<label>Ghi chú</label><input id="lcc-nt" maxlength="250" value="' + esc(s.note || '') + '" placeholder="vd: nghỉ ốm, việc gia đình">' +
      '<div class="lcc-row"><button class="lcc-btn" data-save>Lưu</button>' + (s.type ? '<button class="lcc-btn ghost" data-clear>Xoá ngày này</button>' : '') + '</div>' +
      '<div class="lcc-err" id="lcc-serr"></div></div>';
    document.body.appendChild(sheet);
    function close() { sheet.remove(); }
    sheet.onclick = function (e) { if (e.target === sheet) close(); };
    sheet.querySelector('[data-x]').onclick = close;
    sheet.querySelectorAll('.lcc-opt').forEach(function (b) { b.onclick = function () {
      cur = b.dataset.t; sheet.querySelectorAll('.lcc-opt').forEach(function (x) { x.classList.toggle('on', x === b); }); }; });
    function save(body) {
      api('shift', body).then(function () { close(); load(); })
        .catch(function (e) { sheet.querySelector('#lcc-serr').textContent = e.message; });
    }
    sheet.querySelector('[data-save]').onclick = function () {
      if (!cur) { sheet.querySelector('#lcc-serr').textContent = 'Chọn một loại ca.'; return; }
      save({ day: key, type: cur, extra_hours: extra, note: sheet.querySelector('#lcc-nt').value });
    };
    var clr = sheet.querySelector('[data-clear]');
    if (clr) clr.onclick = function () { save({ day: key, type: 'clear' }); };
  }

  /* ---------------------------------------------------------------- phiếu lương (theo mẫu cũ) */
  function renderSlip(p) {
    var rows = (p.lines || []).map(function (l) {
      return '<tr><td>' + esc(l[0]) + (l[2] ? '<small>' + esc(l[2]) + '</small>' : '') + '</td><td class="' + (l[1] < 0 ? 'neg' : '') + '">' + money(l[1]) + '</td></tr>'; }).join('');
    return '<div class="lcc-slip"><div class="lcc-sh"><b>PHIẾU LƯƠNG ' + esc(p.title || '') + '</b><span>' + esc(p.company || 'Lucas Combo') + '</span></div>' +
      '<table>' + rows + '<tr class="tot"><td>THỰC NHẬN</td><td>' + money(p.net) + '</td></tr>' +
      (p.paid ? '<tr><td>Đã ứng</td><td class="neg">' + money(-p.paid) + '</td></tr><tr class="tot"><td>CÒN NHẬN</td><td>' + money(p.remain) + '</td></tr>' : '') +
      '</table>' + (p.note ? '<p class="lcc-mut">' + esc(p.note) + '</p>' : '') + '</div>';
  }

  S.month = ym(new Date());
  S.token ? load() : renderLogin();
})();
