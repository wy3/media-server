/**
 * PHP Media Server landing page behaviour: screenshot tabs, copy-to-clipboard,
 * scroll reveal and the sticky nav shadow.
 *
 * Chinese and English are separate static pages (see `en/`), so no runtime
 * translation happens here; the current language is read from `<html data-lang>`.
 */
(function () {
  'use strict';

  var isEn = document.documentElement.getAttribute('data-lang') === 'en';
  var BASE = document.documentElement.getAttribute('data-base') || '';

  /**
   * Admin console screenshots per panel, per language.
   * @type {Record<string, {zh: string, en: string}>}
   */
  var SHOTS = {
    dashboard: { zh: 'screenshots/dashboard.png', en: 'screenshots/dashboard-en.png' },
    live: { zh: 'screenshots/live-preview.png', en: 'screenshots/live-en.png' },
    playback: { zh: 'screenshots/playback.png', en: 'screenshots/record-en.png' },
    settings: { zh: 'screenshots/settings.png', en: 'screenshots/settings-en.png' }
  };

  /**
   * Captions for each screenshot panel, per language.
   * @type {Record<string, {zh: string, en: string}>}
   */
  var CAPTIONS = {
    dashboard: {
      zh: '仪表盘：推流路数、播放连接、运行时长与内存占用',
      en: 'Dashboard: stream count, playback connections, uptime and memory'
    },
    live: {
      zh: '直播管理：分辨率、帧率、编码、播放数与内嵌预览',
      en: 'Live streams: resolution, frame rate, codec and viewer counts'
    },
    playback: {
      zh: '录像回放：选择起止时间，直接在浏览器里回看',
      en: 'Recordings: browse segments and replay any time range in the browser'
    },
    settings: {
      zh: '系统设置：录像开关、目录、分片与分段时长',
      en: 'Settings: recording switch, paths, fragment and segment durations'
    }
  };

  /**
   * Alt text for each screenshot panel, per language.
   * @type {Record<string, {zh: string, en: string}>}
   */
  var ALTS = {
    dashboard: { zh: '管理后台仪表盘', en: 'Admin console dashboard' },
    live: { zh: '直播管理页面', en: 'Live stream management page' },
    playback: { zh: '录像回放弹窗', en: 'Recording replay dialog' },
    settings: { zh: '系统设置页面', en: 'Settings page' }
  };

  var TEXT = isEn
    ? { copy: 'Copy', copied: 'Copied' }
    : { copy: '复制', copied: '已复制' };

  var currentShot = 'dashboard';

  /**
   * Render the screenshot for the active tab.
   * @returns {void}
   */
  function renderShot() {
    var img = document.getElementById('shotImg');
    var cap = document.getElementById('shotCap');
    var shot = SHOTS[currentShot];
    if (!img || !shot) return;

    img.src = BASE + shot[isEn ? 'en' : 'zh'];
    img.alt = ALTS[currentShot][isEn ? 'en' : 'zh'];
    if (cap) cap.textContent = CAPTIONS[currentShot][isEn ? 'en' : 'zh'];

    document.querySelectorAll('[data-shot]').forEach(function (tab) {
      var active = tab.getAttribute('data-shot') === currentShot;
      tab.classList.toggle('is-active', active);
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
    });
  }

  document.querySelectorAll('[data-shot]').forEach(function (tab) {
    tab.addEventListener('click', function () {
      currentShot = tab.getAttribute('data-shot');
      renderShot();
    });
  });

  document.querySelectorAll('.copy').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var code = btn.parentElement.querySelector('code');
      if (!code) return;

      var done = function () {
        btn.classList.add('is-done');
        btn.textContent = TEXT.copied;
        window.setTimeout(function () {
          btn.classList.remove('is-done');
          btn.textContent = TEXT.copy;
        }, 1600);
      };

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(code.textContent).then(done, function () {
          /* clipboard denied, leave the button untouched */
        });
      }
    });
  });

  var nav = document.getElementById('nav');
  var onScroll = function () {
    if (nav) nav.classList.toggle('is-scrolled', window.scrollY > 8);
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  var revealables = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-in');
          io.unobserve(entry.target);
        }
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    revealables.forEach(function (el) { io.observe(el); });
  } else {
    revealables.forEach(function (el) { el.classList.add('is-in'); });
  }

  renderShot();
})();
