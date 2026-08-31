/* 媒体服务器管理后台 - 单页应用逻辑 */
(function () {
  'use strict';

  var token = localStorage.getItem('admin_token') || '';
  var currentPanel = 'dashboard';
  var livePlayer = null;      // mpegts.js 直播播放器
  var currentRecordPath = ''; // 当前回放的推流路径
  var pollTimer = null;

  var $ = function (id) { return document.getElementById(id); };

  /* ---------------- API ---------------- */

  function handleUnauthorized() {
    token = '';
    localStorage.removeItem('admin_token');
    clearPolling();
    showLogin();
  }

  function api(name, args) {
    args = args || [];
    return fetch('/api', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Auth-Token': token
      },
      body: JSON.stringify({ name: name, args: args })
    }).then(function (res) {
      if (res.status === 401) {
        handleUnauthorized();
        throw new Error('登录已过期，请重新登录');
      }
      if (res.status === 404) {
        throw new Error('接口不存在');
      }
      return res.json();
    }).then(function (data) {
      if (data && typeof data === 'object' && data.code !== undefined && data.code !== 200) {
        throw new Error(data.msg || '请求失败');
      }
      return data;
    });
  }

  /* ---------------- 登录 / 登出 ---------------- */

  function showLogin() {
    $('appView').classList.add('hidden');
    $('loginView').classList.remove('hidden');
    $('loginError').classList.add('hidden');
  }

  function showApp() {
    $('loginView').classList.add('hidden');
    $('appView').classList.remove('hidden');
    api('getSettings').then(function (s) {
      $('sideUser').textContent = '管理员：' + (s && s.admin ? s.admin.username : 'admin');
    }).catch(function () {});
    switchPanel('dashboard');
    startPolling();
  }

  $('loginForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var username = $('loginUser').value.trim();
    var password = $('loginPass').value;
    var err = $('loginError');
    err.classList.add('hidden');
    fetch('/api', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: 'login', args: [username, password] })
    }).then(function (res) {
      if (res.status === 401) {
        return res.json().then(function (d) { throw new Error((d && d.msg) || '用户名或密码错误'); });
      }
      return res.json();
    }).then(function (data) {
      if (!data || !data.token) { throw new Error('登录失败'); }
      token = data.token;
      localStorage.setItem('admin_token', token);
      showApp();
    }).catch(function (e) {
      err.textContent = e.message;
      err.classList.remove('hidden');
    });
  });

  $('logoutBtn').addEventListener('click', function () {
    api('logout', [token]).catch(function () {});
    handleUnauthorized();
  });

  /* ---------------- 导航 ---------------- */

  Array.prototype.forEach.call(document.querySelectorAll('.nav-item'), function (item) {
    item.addEventListener('click', function () {
      switchPanel(item.getAttribute('data-panel'));
    });
  });

  var PANEL_TITLES = {
    dashboard: '仪表盘',
    live: '直播管理',
    record: '录像管理',
    settings: '系统设置'
  };

  function switchPanel(name) {
    currentPanel = name;
    Array.prototype.forEach.call(document.querySelectorAll('.nav-item'), function (item) {
      item.classList.toggle('active', item.getAttribute('data-panel') === name);
    });
    Array.prototype.forEach.call(document.querySelectorAll('.panel'), function (p) {
      p.classList.add('hidden');
    });
    $('panel-' + name).classList.remove('hidden');
    $('panelTitle').textContent = PANEL_TITLES[name] || name;

    if (name === 'dashboard') { refreshDashboard(); }
    if (name === 'live') { refreshLive(); }
    if (name === 'record') { refreshRecord(); }
    if (name === 'settings') { refreshSettings(); }
  }

  /* ---------------- 轮询 ---------------- */

  function startPolling() {
    clearPolling();
    pollTimer = setInterval(function () {
      if (currentPanel === 'dashboard') { refreshDashboard(); }
      if (currentPanel === 'live') { refreshLive(); }
    }, 5000);
  }

  function clearPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  function setConn(ok) {
    var el = $('connStatus');
    el.textContent = ok ? '已连接' : '连接失败';
    el.classList.toggle('off', !ok);
  }

  /* ---------------- 仪表盘 ---------------- */

  function refreshDashboard() {
    api('getServerStats').then(function (s) {
      setConn(true);
      $('statPublish').textContent = s.publishCount || 0;
      $('statPlay').textContent = s.playCount || 0;
      $('statUptime').textContent = fmtDuration(s.uptime || 0);
      $('statMemory').textContent = fmtBytes(s.memory || 0);
    }).catch(function () { setConn(false); });

    api('listPushStream').then(function (list) {
      return Promise.all([
        Promise.resolve(list),
        api('getPlayStreamCount').then(function (pc) {
          var map = {};
          (pc || []).forEach(function (item) { map[item.path] = item.count; });
          return map;
        })
      ]);
    }).then(function (r) {
      var list = r[0] || [], map = r[1] || {};
      $('dashLiveCount').textContent = '共 ' + list.length + ' 路';
      var tbody = $('dashLiveBody');
      if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="3" class="empty">暂无直播</td></tr>';
        return;
      }
      tbody.innerHTML = list.map(function (st) {
        var p = st.publishStreamPath || '-';
        return '<tr>' +
          '<td>' + esc(p) + '</td>' +
          '<td>' + (st.videoWidth ? st.videoWidth + '×' + st.videoHeight : '-') + '</td>' +
          '<td>' + (map[p] || 0) + '</td>' +
          '</tr>';
      }).join('');
    }).catch(function () {});
  }

  /* ---------------- 直播管理 ---------------- */

  $('refreshLive').addEventListener('click', refreshLive);

  function refreshLive() {
    Promise.all([
      api('listPushStream'),
      api('getPlayStreamCount')
    ]).then(function (r) {
      var list = r[0] || [], pc = r[1] || [];
      var map = {};
      pc.forEach(function (item) { map[item.path] = item.count; });
      setConn(true);
      $('liveCount').textContent = '共 ' + list.length + ' 路';
      var tbody = $('liveBody');
      if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="empty">暂无直播流</td></tr>';
        return;
      }
      tbody.innerHTML = list.map(function (st) {
        var p = st.publishStreamPath || '';
        var codec = [st.videoCodecName || '', st.audioCodecName || ''].filter(Boolean).join(' / ') || '-';
        return '<tr>' +
          '<td>' + esc(p) + '</td>' +
          '<td>' + (st.videoWidth ? st.videoWidth + '×' + st.videoHeight : '-') + '</td>' +
          '<td>' + (st.videoFps || '-') + '</td>' +
          '<td>' + esc(codec) + '</td>' +
          '<td>' + (map[p] || 0) + '</td>' +
          '<td><button class="btn sm" data-live="' + esc(p) + '">预览</button></td>' +
          '</tr>';
      }).join('');
    }).catch(function (e) {
      setConn(false);
      if (e && e.message === '登录已过期，请重新登录') { return; }
      $('liveBody').innerHTML = '<tr><td colspan="6" class="empty">加载失败</td></tr>';
    });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-live]');
    if (btn) { openLive(btn.getAttribute('data-live')); }
  });

  function openLive(path) {
    $('liveModalTitle').textContent = '直播预览 - ' + path;
    $('liveMsg').textContent = '';
    showModal('liveModal');
    var video = $('liveVideo');
    if (typeof mpegts === 'undefined') {
      $('liveMsg').textContent = 'mpegts.js 加载失败（需要网络），无法进行直播预览';
      return;
    }
    if (livePlayer) { livePlayer.destroy(); livePlayer = null; }
    try {
      livePlayer = mpegts.createPlayer({
        type: 'flv',
        url: path + '.flv',
        isLive: true,
        cors: true
      });
      livePlayer.attachMediaElement(video);
      livePlayer.load();
      livePlayer.play();
      $('liveMsg').textContent = '正在连接直播流 ' + path + '.flv';
    } catch (err) {
      $('liveMsg').textContent = '预览启动失败：' + err.message;
    }
  }

  /* ---------------- 录像管理 ---------------- */

  $('refreshRecord').addEventListener('click', refreshRecord);

  function refreshRecord() {
    api('listRecordFiles').then(function (list) {
      setConn(true);
      list = list || [];
      $('recordCount').textContent = '共 ' + list.length + ' 条';
      var tbody = $('recordBody');
      if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="empty">暂无录像</td></tr>';
        return;
      }
      tbody.innerHTML = list.map(function (r) {
        return '<tr>' +
          '<td>' + esc(r.path || '-') + '</td>' +
          '<td>' + fmt(r.start) + '</td>' +
          '<td>' + fmt(r.end) + '</td>' +
          '<td>' + fmtDuration((r.duration || 0) / 1000) + '</td>' +
          '<td>' + esc(r.file || '-') + '</td>' +
          '<td><button class="btn sm" data-record-path="' + esc(r.path || '') + '" data-start="' + (r.start || 0) + '" data-end="' + (r.end || 0) + '">回放</button></td>' +
          '</tr>';
      }).join('');
    }).catch(function (e) {
      setConn(false);
      if (e && e.message === '登录已过期，请重新登录') { return; }
      $('recordBody').innerHTML = '<tr><td colspan="6" class="empty">加载失败</td></tr>';
    });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-record-path]');
    if (btn) {
      openPlayback(
        btn.getAttribute('data-record-path'),
        parseInt(btn.getAttribute('data-start'), 10),
        parseInt(btn.getAttribute('data-end'), 10)
      );
    }
  });

  function openPlayback(path, start, end) {
    currentRecordPath = path;
    $('playModalTitle').textContent = '录像回放 - ' + path;
    $('playMsg').textContent = '';
    $('playStart').value = toLocalInput(start);
    $('playEnd').value = toLocalInput(end);
    $('playVideo').removeAttribute('src');
    showModal('playModal');
  }

  $('playBtn').addEventListener('click', function () {
    var start = new Date($('playStart').value).getTime();
    var end = new Date($('playEnd').value).getTime();
    if (isNaN(start) || isNaN(end) || end <= start) {
      $('playMsg').textContent = '请选择有效的时间范围（结束需晚于开始）';
      return;
    }
    $('playMsg').textContent = '正在加载录像…';
    var url = '/playback/' + currentRecordPath.replace(/^\//, '') + '?start=' + start + '&end=' + end;
    var video = $('playVideo');
    video.src = url;
    video.play().then(function () {
      $('playMsg').textContent = '';
    }).catch(function () {
      $('playMsg').textContent = '播放器暂不可用，请手动点击播放';
    });
  });

  /* ---------------- 系统设置 ---------------- */

  function refreshSettings() {
    api('getSettings').then(function (s) {
      setConn(true);
      s = s || {};
      var rec = s.recorder || {};
      $('settingsRecorder').innerHTML =
        '<dt>录像开关</dt><dd>' + (rec.enabled ? '开启' : '关闭') + '</dd>' +
        '<dt>录像目录</dt><dd>' + esc(rec.recordPath || '-') + '</dd>' +
        '<dt>分片时长</dt><dd>' + (rec.fragmentDurationMs || '-') + ' ms</dd>' +
        '<dt>分段时长</dt><dd>' + (rec.segmentDurationMs || '-') + ' ms</dd>';
      $('settingsAdmin').innerHTML =
        '<dt>账号</dt><dd>' + esc((s.admin && s.admin.username) || '-') + '</dd>';
    }).catch(function () { setConn(false); });
  }

  /* ---------------- 弹窗 ---------------- */

  function showModal(id) {
    document.getElementById(id).classList.remove('hidden');
  }

  document.addEventListener('click', function (e) {
    var close = e.target.closest('[data-close]');
    if (close) {
      closeModal(close.getAttribute('data-close'));
    }
  });

  function closeModal(id) {
    if (id === 'liveModal' && livePlayer) { livePlayer.destroy(); livePlayer = null; }
    if (id === 'playModal') { $('playVideo').pause(); $('playVideo').removeAttribute('src'); }
    document.getElementById(id).classList.add('hidden');
  }

  /* ---------------- 工具 ---------------- */

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function pad(n) { return String(n).padStart(2, '0'); }

  function fmt(ms) {
    if (!ms) { return '-'; }
    var d = new Date(ms);
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
      ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
  }

  function fmtDuration(sec) {
    sec = Math.max(0, Math.floor(sec || 0));
    var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
    return (h > 0 ? h + ':' + pad(m) : pad(m)) + ':' + pad(s);
  }

  function fmtBytes(n) {
    n = n || 0;
    if (n < 1024) { return n + ' B'; }
    if (n < 1048576) { return (n / 1024).toFixed(1) + ' KB'; }
    if (n < 1073741824) { return (n / 1048576).toFixed(1) + ' MB'; }
    return (n / 1073741824).toFixed(2) + ' GB';
  }

  function toLocalInput(ms) {
    var d = new Date(ms);
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
      'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
  }

  /* ---------------- 初始化 ---------------- */

  if (token) {
    // 校验 token 是否仍有效
    api('getServerStats').then(function () {
      showApp();
    }).catch(function (err) {
      if (err && err.message === '登录已过期，请重新登录') { return; }
      showLogin();
    });
  } else {
    showLogin();
  }
})();
