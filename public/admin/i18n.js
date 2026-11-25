/**
 * 管理后台多语言支持（简体中文 / English）。
 *
 * 词典中的值可以是字符串，也可以是 `{one, other}` 形式的单复数变体：
 * 当传入参数含有数字 `count` 时，会按 `count` 自动选择变体。
 * 语言选择持久化在 localStorage 的 `admin_lang` 键中，缺省按浏览器语言判断。
 */
(function (global) {
  'use strict';

  /** 语言持久化键 */
  var STORAGE_KEY = 'admin_lang';

  /** 兜底语言（词典键以简体中文语义编写） */
  var DEFAULT_LANG = 'zh-CN';

  /** @type {Object<string, Object<string, string|{one:string, other:string}>>} */
  var DICT = {
    'zh-CN': {
      'app.title': '媒体服务器管理系统',
      'login.subtitle': '集直播、录像与回放于一体的管理控制台',
      'login.username': '账号',
      'login.password': '密码',
      'login.passwordPlaceholder': '请输入密码',
      'login.submit': '登 录',
      'login.failed': '登录失败',
      'login.invalidCredentials': '用户名或密码错误',
      'login.expired': '登录已过期，请重新登录',

      'nav.dashboard': '仪表盘',
      'nav.live': '直播管理',
      'nav.record': '录像管理',
      'nav.settings': '系统设置',
      'brand.name': '媒体服务器',
      'sidebar.user': '管理员：{name}',
      'sidebar.logout': '退出登录',
      'lang.label': '语言',

      'conn.connected': '已连接',
      'conn.failed': '连接失败',

      'stat.publish': '直播推流',
      'stat.play': '播放连接',
      'stat.uptime': '运行时长',
      'stat.memory': '内存占用',

      'dashboard.currentLive': '当前直播',
      'dashboard.empty': '暂无直播',
      'live.empty': '暂无直播流',
      'record.empty': '暂无录像',

      'table.path': '路径',
      'table.resolution': '分辨率',
      'table.players': '播放数',
      'table.fps': '帧率',
      'table.codec': '编码',
      'table.actions': '操作',
      'table.recordPath': '推流路径',
      'table.start': '开始时间',
      'table.end': '结束时间',
      'table.duration': '时长',
      'table.file': '文件',

      'action.refresh': '刷新',
      'action.preview': '预览',
      'action.playback': '回放',
      'action.play': '播放',

      'count.streams': { one: '共 {count} 路', other: '共 {count} 路' },
      'count.segments': { one: '共 {count} 条', other: '共 {count} 条' },

      'live.previewTitle': '直播预览 - {path}',
      'live.connecting': '正在连接直播流 {path}.flv',
      'live.mpegtsMissing': 'mpegts.js 加载失败（需要网络），无法进行直播预览',
      'live.startFailed': '预览启动失败：{msg}',
      'live.hevcUnsupported': '当前浏览器/系统不支持 H.265 解码，画面将无法显示。Windows 请安装「HEVC 视频扩展」，或改用支持 HEVC 的浏览器。',

      'record.playbackTitle': '录像回放 - {path}',
      'record.startTime': '开始时间',
      'record.endTime': '结束时间',
      'record.invalidRange': '请选择有效的时间范围（结束需晚于开始）',
      'record.loading': '正在加载录像…',
      'record.playerUnavailable': '播放器暂不可用，请手动点击播放',

      'settings.recorder': '录像配置',
      'settings.admin': '管理账号',
      'settings.recorderEnabled': '录像开关',
      'settings.recordPath': '录像目录',
      'settings.fragment': '分片时长',
      'settings.segment': '分段时长',
      'settings.username': '账号',
      'settings.on': '开启',
      'settings.off': '关闭',

      'error.loadFailed': '加载失败',
      'error.endpointNotFound': '接口不存在',
      'error.requestFailed': '请求失败'
    },
    'en-US': {
      'app.title': 'Media Server Console',
      'login.subtitle': 'Live streaming, recording and playback in one console',
      'login.username': 'Username',
      'login.password': 'Password',
      'login.passwordPlaceholder': 'Enter password',
      'login.submit': 'Sign in',
      'login.failed': 'Sign-in failed',
      'login.invalidCredentials': 'Invalid username or password',
      'login.expired': 'Session expired, please sign in again',

      'nav.dashboard': 'Dashboard',
      'nav.live': 'Live streams',
      'nav.record': 'Recordings',
      'nav.settings': 'Settings',
      'brand.name': 'Media Server',
      'sidebar.user': 'Admin: {name}',
      'sidebar.logout': 'Sign out',
      'lang.label': 'Language',

      'conn.connected': 'Connected',
      'conn.failed': 'Disconnected',

      'stat.publish': 'Live streams',
      'stat.play': 'Playback sessions',
      'stat.uptime': 'Uptime',
      'stat.memory': 'Memory',

      'dashboard.currentLive': 'Active streams',
      'dashboard.empty': 'No active streams',
      'live.empty': 'No active streams',
      'record.empty': 'No recordings',

      'table.path': 'Path',
      'table.resolution': 'Resolution',
      'table.players': 'Players',
      'table.fps': 'FPS',
      'table.codec': 'Codec',
      'table.actions': 'Actions',
      'table.recordPath': 'Publish path',
      'table.start': 'Start',
      'table.end': 'End',
      'table.duration': 'Duration',
      'table.file': 'File',

      'action.refresh': 'Refresh',
      'action.preview': 'Preview',
      'action.playback': 'Playback',
      'action.play': 'Play',

      'count.streams': { one: '{count} stream', other: '{count} streams' },
      'count.segments': { one: '{count} segment', other: '{count} segments' },

      'live.previewTitle': 'Live preview - {path}',
      'live.connecting': 'Connecting to {path}.flv',
      'live.mpegtsMissing': 'mpegts.js failed to load (network required), live preview is unavailable',
      'live.startFailed': 'Failed to start preview: {msg}',
      'live.hevcUnsupported': 'This browser/OS cannot decode H.265, so the video will stay blank. On Windows install "HEVC Video Extensions", or use a browser with HEVC support.',

      'record.playbackTitle': 'Playback - {path}',
      'record.startTime': 'Start time',
      'record.endTime': 'End time',
      'record.invalidRange': 'Please pick a valid range (end must be after start)',
      'record.loading': 'Loading recording…',
      'record.playerUnavailable': 'Player unavailable, press play manually',

      'settings.recorder': 'Recording',
      'settings.admin': 'Admin account',
      'settings.recorderEnabled': 'Recording enabled',
      'settings.recordPath': 'Recording directory',
      'settings.fragment': 'Fragment duration',
      'settings.segment': 'Segment duration',
      'settings.username': 'Username',
      'settings.on': 'On',
      'settings.off': 'Off',

      'error.loadFailed': 'Failed to load',
      'error.endpointNotFound': 'Endpoint not found',
      'error.requestFailed': 'Request failed'
    }
  };

  /** 后台下拉框可选语言 */
  var LANGS = ['zh-CN', 'en-US'];

  /**
   * 把任意语言标记归一化为词典中存在的语言。
   *
   * @param {string} lang 语言标记，如 `zh`、`zh-CN`、`en`、`en-US`
   * @returns {string} 归一化后的语言标记
   */
  function normalizeLang(lang) {
    if (!lang) { return DEFAULT_LANG; }
    var lower = String(lang).toLowerCase();
    for (var i = 0; i < LANGS.length; i++) {
      var candidate = LANGS[i];
      if (candidate.toLowerCase() === lower) { return candidate; }
    }
    //只给到语种时按前缀匹配，例如浏览器返回的 `en` 命中 `en-US`
    var prefix = lower.split('-')[0];
    for (var j = 0; j < LANGS.length; j++) {
      if (LANGS[j].toLowerCase().indexOf(prefix) === 0) { return LANGS[j]; }
    }
    return DEFAULT_LANG;
  }

  /**
   * 读取初始语言：优先 localStorage，其次浏览器语言。
   *
   * @returns {string} 语言标记
   */
  function detectLang() {
    var stored = '';
    try {
      stored = global.localStorage.getItem(STORAGE_KEY) || '';
    } catch (e) {
      stored = '';
    }
    if (stored) { return normalizeLang(stored); }
    return normalizeLang(global.navigator && global.navigator.language);
  }

  var current = detectLang();

  /**
   * 取当前语言。
   *
   * @returns {string} 语言标记
   */
  function getLang() {
    return current;
  }

  /**
   * 设置当前语言并持久化（不负责刷新界面，界面由调用方重新渲染）。
   *
   * @param {string} lang 语言标记
   * @returns {string} 实际生效的语言标记
   */
  function setLang(lang) {
    current = normalizeLang(lang);
    try {
      global.localStorage.setItem(STORAGE_KEY, current);
    } catch (e) {
      //隐私模式下 localStorage 可能不可写，忽略即可，语言仍在本次会话生效
    }
    return current;
  }

  /**
   * 取翻译文本。
   *
   * @param {string} key 词典键
   * @param {Object<string, (string|number)>} [params] 插值参数，同时用于选择单复数变体
   * @returns {string} 翻译后的文本；词典缺失时返回键名本身，便于发现漏翻
   */
  function t(key, params) {
    var dict = DICT[current] || DICT[DEFAULT_LANG];
    var entry = dict[key];
    if (entry === undefined) { entry = DICT[DEFAULT_LANG][key]; }
    if (entry === undefined) { return key; }

    var text = entry;
    if (typeof entry === 'object') {
      var n = params && Number(params.count);
      text = n === 1 ? entry.one : entry.other;
    }
    if (!params) { return text; }
    return text.replace(/\{(\w+)\}/g, function (match, name) {
      return params[name] === undefined ? match : String(params[name]);
    });
  }

  /**
   * 把当前语言应用到 DOM。
   *
   * 支持三种标记：`data-i18n`（文本）、`data-i18n-placeholder`、`data-i18n-title`。
   *
   * @param {Document|Element} [root] 作用范围，默认整页
   * @returns {void}
   */
  function apply(root) {
    root = root || global.document;
    var nodes = root.querySelectorAll('[data-i18n]');
    Array.prototype.forEach.call(nodes, function (el) {
      el.textContent = t(el.getAttribute('data-i18n'));
    });
    nodes = root.querySelectorAll('[data-i18n-placeholder]');
    Array.prototype.forEach.call(nodes, function (el) {
      el.setAttribute('placeholder', t(el.getAttribute('data-i18n-placeholder')));
    });
    nodes = root.querySelectorAll('[data-i18n-title]');
    Array.prototype.forEach.call(nodes, function (el) {
      el.setAttribute('title', t(el.getAttribute('data-i18n-title')));
    });
    if (root === global.document) {
      global.document.documentElement.lang = current;
      global.document.title = t('app.title');
    }
  }

  global.AdminI18n = {
    LANGS: LANGS,
    getLang: getLang,
    setLang: setLang,
    normalizeLang: normalizeLang,
    t: t,
    apply: apply
  };
})(window);
