/**
 * 媒体服务器管理后台 - 单页应用逻辑。
 *
 * 界面文案统一走 `AdminI18n.t()`（见 i18n.js），切换语言后重新渲染当前面板。
 */
(function () {
  'use strict';

  var i18n = window.AdminI18n;

  /**
   * 取翻译文本。
   *
   * @param {string} key 词典键
   * @param {Object<string, (string|number)>} [params] 插值参数
   * @returns {string} 翻译后的文本
   */
  function t(key, params) { return i18n.t(key, params); }

  /** 语言下拉框的显示名（用各自母语书写，不随界面语言变化） */
  var LANG_LABELS = { 'zh-CN': '简体中文', 'en-US': 'English' };

  var token = localStorage.getItem('admin_token') || '';
  var currentPanel = 'dashboard';
  var livePlayer = null;      // mpegts.js 直播播放器
  var currentLivePath = '';   // 当前预览的推流路径
  var currentRecordPath = ''; // 当前回放的推流路径
  var pollTimer = null;
  var loggedIn = false;
  var connOk = true;
  var currentUser = 'admin';

  /**
   * 弹窗内提示文案的状态，用于切换语言时按当前语言重新渲染。
   *
   * @type {{live: ?{key: string, params: ?Object}, play: ?{key: string, params: ?Object}}}
   */
  var msgState = { live: null, play: null };

  /** 登录错误提示的词典键，切换语言时用于重绘 */
  var loginErrorKey = '';

  var $ = function (id) { return document.getElementById(id); };

  /* ---------------- 多语言 ---------------- */

  /**
   * 初始化所有语言下拉框并绑定切换事件。
   *
   * @returns {void}
   */
  function initLangSelectors() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-lang-select]'), function (sel) {
      sel.innerHTML = i18n.LANGS.map(function (lang) {
        return '<option value="' + lang + '">' + LANG_LABELS[lang] + '</option>';
      }).join('');
      sel.value = i18n.getLang();
      sel.addEventListener('change', function () { applyLang(sel.value); });
    });
  }

  /**
   * 切换界面语言并重新渲染。
   *
   * 已登录时会重新拉取当前面板数据，保证表格内的动态文案（如按钮、空状态）同步切换。
   *
   * @param {string} lang 语言标记
   * @returns {void}
   */
  function applyLang(lang) {
    i18n.setLang(lang);
    i18n.apply(document);
    Array.prototype.forEach.call(document.querySelectorAll('[data-lang-select]'), function (sel) {
      sel.value = i18n.getLang();
    });
    renderChrome();
    if (loggedIn) {
      switchPanel(currentPanel);
    }
  }

  /**
   * 重新渲染不依赖接口数据的静态文案（面板标题、连接状态、侧栏用户、弹窗标题）。
   *
   * @returns {void}
   */
  function renderChrome() {
    $('panelTitle').textContent = t('nav.' + currentPanel);
    setConn(connOk);
    $('sideUser').textContent = t('sidebar.user', { name: currentUser });
    if (currentLivePath) {
      $('liveModalTitle').textContent = t('live.previewTitle', { path: currentLivePath });
    }
    if (currentRecordPath) {
      $('playModalTitle').textContent = t('record.playbackTitle', { path: currentRecordPath });
    }
    renderMsg('live');
    renderMsg('play');
    if (loginErrorKey) {
      $('loginError').textContent = t(loginErrorKey);
    }
  }

  /**
   * 设置弹窗提示文案，并记录对应的词典键，便于切换语言后重绘。
   *
   * @param {'live'|'play'} which 弹窗标识
   * @param {?string} key 词典键；传 null 表示清空
   * @param {Object<string, (string|number)>} [params] 插值参数
   * @returns {void}
   */
  function setMsg(which, key, params) {
    msgState[which] = key ? { key: key, params: params || null } : null;
    renderMsg(which);
  }

  /**
   * 按当前语言重绘弹窗提示文案。
   *
   * @param {'live'|'play'} which 弹窗标识
   * @returns {void}
   */
  function renderMsg(which) {
    var el = $(which === 'live' ? 'liveMsg' : 'playMsg');
    var state = msgState[which];
    el.textContent = state ? t(state.key, state.params) : '';
  }

  /* ---------------- API ---------------- */

  function handleUnauthorized() {
    token = '';
    loggedIn = false;
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
        var expired = new Error(t('login.expired'));
        expired.authExpired = true;
        throw expired;
      }
      if (res.status === 404) {
        throw new Error(t('error.endpointNotFound'));
      }
      return res.json();
    }).then(function (data) {
      if (data && typeof data === 'object' && data.code !== undefined && data.code !== 200) {
        throw new Error(data.msg || t('error.requestFailed'));
      }
      return data;
    });
  }

  /* ---------------- 登录 / 登出 ---------------- */

  function showLogin() {
    $('appView').classList.add('hidden');
    $('loginView').classList.remove('hidden');
    $('loginError').classList.add('hidden');
    loginErrorKey = '';
  }

  function showApp() {
    loggedIn = true;
    $('loginView').classList.add('hidden');
    $('appView').classList.remove('hidden');
    api('getSettings').then(function (s) {
      currentUser = (s && s.admin ? s.admin.username : 'admin');
      $('sideUser').textContent = t('sidebar.user', { name: currentUser });
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
    loginErrorKey = '';
    fetch('/api', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: 'login', args: [username, password] })
    }).then(function (res) {
      if (res.status === 401) {
        return res.json().then(function () {
          var e = new Error(t('login.invalidCredentials'));
          e.i18nKey = 'login.invalidCredentials';
          throw e;
        });
      }
      return res.json();
    }).then(function (data) {
      if (!data || !data.token) {
        var e = new Error(t('login.failed'));
        e.i18nKey = 'login.failed';
        throw e;
      }
      token = data.token;
      localStorage.setItem('admin_token', token);
      showApp();
    }).catch(function (e) {
      loginErrorKey = e.i18nKey || '';
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

  /**
   * 切换左侧导航面板，并刷新对应的数据。
   *
   * @param {string} name 面板名，对应 `nav.<name>` 词典键与 `#panel-<name>` 容器
   * @returns {void}
   */
  function switchPanel(name) {
    currentPanel = name;
    Array.prototype.forEach.call(document.querySelectorAll('.nav-item'), function (item) {
      item.classList.toggle('active', item.getAttribute('data-panel') === name);
    });
    Array.prototype.forEach.call(document.querySelectorAll('.panel'), function (p) {
      p.classList.add('hidden');
    });
    $('panel-' + name).classList.remove('hidden');
    $('panelTitle').textContent = t('nav.' + name);

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
    connOk = ok;
    var el = $('connStatus');
    el.textContent = ok ? t('conn.connected') : t('conn.failed');
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
      $('dashLiveCount').textContent = t('count.streams', { count: list.length });
      var tbody = $('dashLiveBody');
      if (!list.length) {
        tbody.innerHTML = emptyRow(3, t('dashboard.empty'));
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
      $('liveCount').textContent = t('count.streams', { count: list.length });
      var tbody = $('liveBody');
      if (!list.length) {
        tbody.innerHTML = emptyRow(6, t('live.empty'));
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
          '<td><button class="btn sm" data-live="' + esc(p) + '">' + esc(t('action.preview')) + '</button></td>' +
          '</tr>';
      }).join('');
    }).catch(function (e) {
      setConn(false);
      if (e && e.authExpired) { return; }
      $('liveBody').innerHTML = emptyRow(6, t('error.loadFailed'));
    });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-live]');
    if (btn) { openLive(btn.getAttribute('data-live')); }
  });

  function openLive(path) {
    currentLivePath = path;
    $('liveModalTitle').textContent = t('live.previewTitle', { path: path });
    setMsg('live', null);
    showModal('liveModal');
    var video = $('liveVideo');
    if (typeof mpegts === 'undefined') {
      setMsg('live', 'live.mpegtsMissing');
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
      setMsg('live', 'live.connecting', { path: path });
    } catch (err) {
      setMsg('live', 'live.startFailed', { msg: err.message });
    }
  }

  /* ---------------- 录像管理 ---------------- */

  $('refreshRecord').addEventListener('click', refreshRecord);

  function refreshRecord() {
    api('listRecordFiles').then(function (list) {
      setConn(true);
      list = list || [];
      $('recordCount').textContent = t('count.segments', { count: list.length });
      var tbody = $('recordBody');
      if (!list.length) {
        tbody.innerHTML = emptyRow(6, t('record.empty'));
        return;
      }
      tbody.innerHTML = list.map(function (r) {
        return '<tr>' +
          '<td>' + esc(r.path || '-') + '</td>' +
          '<td>' + fmt(r.start) + '</td>' +
          '<td>' + fmt(r.end) + '</td>' +
          '<td>' + fmtDuration((r.duration || 0) / 1000) + '</td>' +
          '<td>' + esc(r.file || '-') + '</td>' +
          '<td><button class="btn sm" data-record-path="' + esc(r.path || '') + '" data-start="' + (r.start || 0) + '" data-end="' + (r.end || 0) + '">' + esc(t('action.playback')) + '</button></td>' +
          '</tr>';
      }).join('');
    }).catch(function (e) {
      setConn(false);
      if (e && e.authExpired) { return; }
      $('recordBody').innerHTML = emptyRow(6, t('error.loadFailed'));
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
    $('playModalTitle').textContent = t('record.playbackTitle', { path: path });
    setMsg('play', null);
    $('playStart').value = toLocalInput(start);
    $('playEnd').value = toLocalInput(end);
    $('playVideo').removeAttribute('src');
    showModal('playModal');
  }

  $('playBtn').addEventListener('click', function () {
    var start = new Date($('playStart').value).getTime();
    var end = new Date($('playEnd').value).getTime();
    if (isNaN(start) || isNaN(end) || end <= start) {
      setMsg('play', 'record.invalidRange');
      return;
    }
    setMsg('play', 'record.loading');
    var url = '/playback/' + currentRecordPath.replace(/^\//, '') + '?start=' + start + '&end=' + end;
    var video = $('playVideo');
    video.src = url;
    video.play().then(function () {
      setMsg('play', null);
    }).catch(function () {
      setMsg('play', 'record.playerUnavailable');
    });
  });

  /* ---------------- 系统设置 ---------------- */

  function refreshSettings() {
    api('getSettings').then(function (s) {
      setConn(true);
      s = s || {};
      var rec = s.recorder || {};
      $('settingsRecorder').innerHTML =
        '<dt>' + esc(t('settings.recorderEnabled')) + '</dt><dd>' + esc(rec.enabled ? t('settings.on') : t('settings.off')) + '</dd>' +
        '<dt>' + esc(t('settings.recordPath')) + '</dt><dd>' + esc(rec.recordPath || '-') + '</dd>' +
        '<dt>' + esc(t('settings.fragment')) + '</dt><dd>' + (rec.fragmentDurationMs || '-') + ' ms</dd>' +
        '<dt>' + esc(t('settings.segment')) + '</dt><dd>' + (rec.segmentDurationMs || '-') + ' ms</dd>';
      $('settingsAdmin').innerHTML =
        '<dt>' + esc(t('settings.username')) + '</dt><dd>' + esc((s.admin && s.admin.username) || '-') + '</dd>';
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
    if (id === 'liveModal') {
      currentLivePath = '';
      if (livePlayer) { livePlayer.destroy(); livePlayer = null; }
    }
    if (id === 'playModal') { $('playVideo').pause(); $('playVideo').removeAttribute('src'); }
    document.getElementById(id).classList.add('hidden');
  }

  /* ---------------- 工具 ---------------- */

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /**
   * 生成一行占位的空状态。
   *
   * @param {number} colspan 跨列数，需与所在表格的列数一致
   * @param {string} text 提示文案
   * @returns {string} 表格行 HTML
   */
  function emptyRow(colspan, text) {
    return '<tr><td colspan="' + colspan + '" class="empty">' + esc(text) + '</td></tr>';
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

  i18n.apply(document);
  initLangSelectors();
  renderChrome();

  if (token) {
    // 校验 token 是否仍有效
    api('getServerStats').then(function () {
      showApp();
    }).catch(function (err) {
      if (err && err.authExpired) { return; }
      showLogin();
    });
  } else {
    showLogin();
  }
})();
