'use strict';

(() => {
  const storageKey = 'saas-wordpress-migrator-v1';
  const form = document.querySelector('#wizard');
  const sections = [...form.querySelectorAll('[data-step]')];
  const errorBox = document.querySelector('#error');
  let csrf = '';
  let step = 0;
  let busy = false;
  let running = false;
  let pauseRequested = false;
  let checkpoint = null;
  let inspected = null;
  let saved = null;
  let resumeConfig = null;

  function element(tag, attributes = {}, text = '') {
    const node = document.createElement(tag);
    for (const [key, value] of Object.entries(attributes)) node.setAttribute(key, value);
    node.textContent = text;
    return node;
  }

  function field(parent, title, name, type = 'text', value = '', required = true) {
    const label = element('label', {}, title);
    const input = element('input', { name, type, autocomplete: 'off' });
    input.value = value;
    input.required = required;
    label.append(input);
    parent.append(label);
    return input;
  }

  for (const side of ['source', 'destination']) {
    const root = document.querySelector(`#${side}-fields`);
    const transfer = element('fieldset');
    transfer.append(element('legend', {}, 'File transfer'));
    const protocolLabel = element('label', {}, 'Protocol');
    const protocol = element('select', { name: `${side}.transfer.protocol` });
    for (const [value, title] of [['ftps', 'FTPS (explicit TLS)'], ['sftp', 'SFTP (SSH)'], ['ftp', 'FTP (unencrypted — trusted networks only)']]) {
      protocol.append(element('option', { value }, title));
    }
    protocolLabel.append(protocol);
    transfer.append(protocolLabel);
    field(transfer, 'Host', `${side}.transfer.host`, 'text');
    const port = field(transfer, 'Port', `${side}.transfer.port`, 'number', '21');
    port.min = '1';
    port.max = '65535';
    field(transfer, 'Username', `${side}.transfer.username`);
    field(transfer, 'Password', `${side}.transfer.password`, 'password');
    field(transfer, 'Absolute WordPress directory', `${side}.transfer.path`, 'text', side === 'source' ? '/public_html' : '/public_html');
    const fingerprint = field(transfer, 'SSH host key fingerprint (40 hexadecimal SHA-1 characters)', `${side}.transfer.fingerprint`, 'text', '', false);
    fingerprint.parentElement.hidden = true;
    protocol.addEventListener('change', () => {
      port.value = protocol.value === 'sftp' ? '22' : '21';
      fingerprint.parentElement.hidden = protocol.value !== 'sftp';
      fingerprint.required = protocol.value === 'sftp';
    });
    transfer.append(element('p', { class: 'hint' }, 'PHP FTPS encrypts traffic but does not verify server certificates. Use host-key-pinned SFTP on untrusted networks; never expose plain FTP to them.'));
    root.append(transfer);
    const database = element('fieldset');
    database.append(element('legend', {}, 'MySQL database'));
    field(database, 'Host (reachable from this server)', `${side}.database.host`, 'text', 'localhost');
    const dbPort = field(database, 'Port', `${side}.database.port`, 'number', '3306');
    dbPort.min = '1';
    dbPort.max = '65535';
    field(database, 'Database name', `${side}.database.name`);
    field(database, 'Username', `${side}.database.username`);
    field(database, 'Password', `${side}.database.password`, 'password', '', false);
    field(database, 'WordPress table prefix', `${side}.database.prefix`, 'text', 'wp_');
    database.append(element('p', { class: 'hint' }, 'Use a network-protected database connection. Remote database access must be allowed by the host.'));
    root.append(database);
  }

  function setStep(next) {
    step = next;
    sections.forEach((section, index) => {
      section.hidden = index !== step;
      section.querySelectorAll('input, select').forEach(input => { input.disabled = index !== step; });
    });
    document.querySelectorAll('#steps li').forEach((item, index) => {
      if (index === step) item.setAttribute('aria-current', 'step');
      else item.removeAttribute('aria-current');
    });
    updateOptional();
    sections[step].querySelector('h2').focus();
  }

  function updateOptional() {
    const content = document.querySelector('#include-content').checked;
    document.querySelector('#content-options').hidden = !content;
    document.querySelectorAll('[name="post-type"]').forEach(input => { input.disabled = step !== 1 || !content; });
    const replace = form.querySelector('[name="admin-mode"]:checked').value === 'replace';
    document.querySelector('#admin-options').hidden = !replace;
    form.querySelectorAll('#admin-options input').forEach(input => {
      input.required = replace;
      input.disabled = step !== 1 || !replace;
    });
    const different = document.querySelector('#different-url').checked;
    document.querySelector('#site-url-options').hidden = !different;
    const site = form.elements.namedItem('migration.siteUrl');
    site.required = different;
    site.disabled = step !== 2 || !different;
  }

  function config() {
    const result = { source: {}, destination: {}, migration: {} };
    for (const input of form.querySelectorAll('input[name], select[name]')) {
      if (!input.name.includes('.')) continue;
      const parts = input.name.split('.');
      let target = result;
      parts.slice(0, -1).forEach(part => { target = target[part] ??= {}; });
      target[parts.at(-1)] = input.type === 'checkbox' ? input.checked : input.value;
    }
    result.migration.postTypes = [...form.querySelectorAll('[name="post-type"]:checked')].map(input => input.value);
    result.migration.admin.mode = form.querySelector('[name="admin-mode"]:checked').value;
    if (!document.querySelector('#different-url').checked) result.migration.siteUrl = result.migration.homeUrl;
    return result;
  }

  function showError(message) {
    errorBox.textContent = message;
    errorBox.hidden = !message;
  }

  async function request(action, settings, state) {
    const response = await fetch('api.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf,
        'X-Migrator-Key': document.querySelector('#access-key').value
      },
      body: JSON.stringify({ action, config: settings, state })
    });
    let data;
    try { data = await response.json(); } catch {
      throw new Error(`The server returned a non-JSON response (${response.status}). Check the PHP configuration and server logs.`);
    }
    if (!response.ok || data.ok === false) throw new Error(data.error || `Request failed (${response.status}).`);
    return data;
  }

  function save() {
    const settings = config();
    for (const side of ['source', 'destination']) {
      delete settings[side].transfer.password;
      delete settings[side].database.password;
      if (settings[side].wordpress) delete settings[side].wordpress.password;
    }
    delete settings.migration.admin.password;
    try {
      localStorage.setItem(storageKey, JSON.stringify({ version: 1, config: settings, checkpoint, inspected }));
    } catch {
      showError('Browser storage is unavailable or full. Keep this tab open: automatic recovery after closing it is unavailable.');
    }
  }

  function contentTypes(types, selected = null) {
    const root = document.querySelector('#content-types');
    root.replaceChildren();
    for (const type of types || []) {
      const name = typeof type === 'string' ? type : type.name;
      const title = typeof type === 'string' ? type : (type.label || name);
      const label = element('label', { class: 'choice' });
      const input = element('input', { type: 'checkbox', name: 'post-type', value: name });
      input.checked = selected === null || selected.includes(name);
      label.append(input, document.createTextNode(`${title}${type.count !== undefined ? ` (${type.count})` : ''}`));
      root.append(label);
    }
    if (!root.children.length) root.append(element('p', {}, 'No content types were found.'));
  }

  function log(message) {
    const root = document.querySelector('#activity');
    root.append(element('li', {}, `${new Date().toLocaleTimeString()} — ${message}`));
    while (root.children.length > 200) root.firstElementChild.remove();
    root.scrollTop = root.scrollHeight;
  }

  function progress(data) {
    const info = data.progress || {};
    const message = info.message || info.phase || 'Batch completed';
    document.querySelector('#phase').textContent = info.phase || 'Migration in progress';
    const bar = document.querySelector('#progress');
    if (Number(info.total) > 0) {
      bar.max = Number(info.total);
      bar.value = Number(info.current) || 0;
      document.querySelector('#progress-detail').textContent = `${info.current || 0} / ${info.total} — ${message}`;
    } else {
      bar.removeAttribute('value');
      document.querySelector('#progress-detail').textContent = message;
    }
    if (info.files !== undefined) {
      document.querySelector('#progress-detail').textContent +=
        ` · Files: ${info.files} · Bytes: ${Number(info.bytes || 0).toLocaleString()} · Rows: ${info.rows || 0} · Tables: ${info.tables || 0}/${info.totalTables || 0}`;
    }
    log(message);
  }

  function stopped(message) {
    running = false;
    document.querySelector('#pause').hidden = true;
    document.querySelector('#retry').hidden = false;
    document.querySelector('#edit-connections').hidden = false;
    document.querySelector('#phase').textContent = message;
  }

  async function migrate() {
    if (running) return;
    running = true;
    pauseRequested = false;
    showError('');
    document.querySelector('#pause').hidden = false;
    document.querySelector('#pause').disabled = false;
    document.querySelector('#retry').hidden = true;
    document.querySelector('#edit-connections').hidden = true;
    const settings = config();
    try {
      while (!pauseRequested) {
        const data = await request('tick', settings, checkpoint);
        checkpoint = data.state;
        save();
        progress(data);
        if (data.done) {
          running = false;
          const link = document.querySelector('#visit-site');
          const target = new URL(settings.migration.homeUrl);
          if (['https:', 'http:'].includes(target.protocol)) link.href = target.href;
          setStep(4);
          return;
        }
      }
      stopped('Paused after the last completed batch.');
    } catch (error) {
      showError(error.message);
      log('Request failed. The last confirmed checkpoint has been kept.');
      stopped('Migration stopped — correct the problem, then resume.');
    }
  }

  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy || running) return;
    busy = true;
    showError('');
    const button = sections[step].querySelector('[type="submit"]');
    if (button) button.disabled = true;
    try {
      if (step === 0) {
        const data = await request('inspect', config(), null);
        const selected = resumeConfig?.migration.postTypes ??
          (inspected ? [...form.querySelectorAll('[name="post-type"]:checked')].map(input => input.value) : null);
        inspected = { contentTypes: data.contentTypes, source: data.source };
        contentTypes(data.contentTypes, selected);
        if (!form.elements.namedItem('migration.homeUrl').value) {
          form.elements.namedItem('migration.homeUrl').value = data.source?.homeUrl || '';
          form.elements.namedItem('migration.siteUrl').value = data.source?.siteUrl || '';
          document.querySelector('#different-url').checked = data.source?.homeUrl !== data.source?.siteUrl;
        }
        setStep(1);
      } else if (step === 1) {
        setStep(2);
      } else if (step === 2) {
        const settings = config();
        if (checkpoint && resumeConfig) {
          // A checkpoint is tied to the original migration; passwords may be refreshed.
          const stable = value => JSON.stringify(value);
          const original = JSON.parse(JSON.stringify(resumeConfig));
          const current = JSON.parse(JSON.stringify(settings));
          for (const value of [original, current]) {
            for (const side of ['source', 'destination']) {
              delete value[side].transfer.password;
              delete value[side].database.password;
              if (value[side].wordpress) delete value[side].wordpress.password;
            }
            delete value.migration.admin.password;
          }
          if (stable(original) !== stable(current)) throw new Error('Resume requires the original connections and migration choices. Discard the saved state to start a different migration.');
        }
        const data = await request('validate', settings, checkpoint);
        if (!checkpoint && data.state) checkpoint = data.state;
        resumeConfig = settings;
        document.querySelector('#resume-notice').hidden = true;
        save();
        setStep(3);
        for (const warning of data.warnings || []) log(`Notice: ${warning}`);
        await migrate();
      }
    } catch (error) {
      showError(error.message);
    } finally {
      busy = false;
      if (button) button.disabled = false;
    }
  });

  form.addEventListener('change', updateOptional);
  form.querySelectorAll('[data-back]').forEach(button => button.addEventListener('click', () => {
    if (!busy && !running) setStep(step - 1);
  }));
  document.querySelector('#select-all').addEventListener('click', () => {
    form.querySelectorAll('[name="post-type"]').forEach(input => { input.checked = true; });
  });
  document.querySelector('#select-none').addEventListener('click', () => {
    form.querySelectorAll('[name="post-type"]').forEach(input => { input.checked = false; });
  });
  document.querySelector('#pause').addEventListener('click', event => {
    pauseRequested = true;
    event.target.disabled = true;
    document.querySelector('#phase').textContent = 'Pausing after the current batch…';
  });
  document.querySelector('#retry').addEventListener('click', migrate);
  document.querySelector('#edit-connections').addEventListener('click', () => setStep(0));
  window.addEventListener('beforeunload', event => {
    if (running) {
      event.preventDefault();
      event.returnValue = '';
    }
  });

  function discard() {
    if (busy || running) return;
    try { localStorage.removeItem(storageKey); } catch { /* Storage may be disabled. */ }
    checkpoint = null;
    saved = null;
    resumeConfig = null;
    document.querySelector('#resume-notice').hidden = true;
  }
  document.querySelector('#discard').addEventListener('click', discard);
  document.querySelector('#finish').addEventListener('click', () => { discard(); window.location.reload(); });
  document.querySelector('#restore').addEventListener('click', () => {
    if (!saved || busy || running) return;
    resumeConfig = saved.config;
    form.querySelectorAll('input[type="password"]').forEach(input => { input.value = ''; });
    for (const input of form.querySelectorAll('input[name], select[name]')) {
      if (!input.name.includes('.') || input.type === 'password') continue;
      const value = input.name.split('.').reduce((result, part) => result?.[part], saved.config);
      if (value === undefined) continue;
      if (input.type === 'checkbox') input.checked = Boolean(value);
      else input.value = value;
    }
    form.querySelector(`[name="admin-mode"][value="${saved.config.migration.admin.mode === 'replace' ? 'replace' : 'keep'}"]`).checked = true;
    for (const side of ['source', 'destination']) {
      const protocol = form.elements.namedItem(`${side}.transfer.protocol`);
      const fingerprint = form.elements.namedItem(`${side}.transfer.fingerprint`);
      fingerprint.parentElement.hidden = protocol.value !== 'sftp';
      fingerprint.required = protocol.value === 'sftp';
    }
    document.querySelector('#different-url').checked = saved.config.migration.siteUrl !== saved.config.migration.homeUrl;
    checkpoint = saved.checkpoint;
    inspected = saved.inspected;
    contentTypes(inspected?.contentTypes, saved.config.migration.postTypes);
    document.querySelector('#resume-notice').hidden = true;
    setStep(0);
    log('Saved checkpoint restored. Reconnect using the original settings.');
  });

  async function initialize() {
    setStep(0);
    try {
      const response = await fetch('api.php', { credentials: 'same-origin', cache: 'no-store' });
      const data = await response.json();
      if (!response.ok || data.ok === false) throw new Error(data.error || 'Backend unavailable.');
      csrf = data.csrf;
      document.querySelector('#startup').textContent = data.setupRequired
        ? 'Server setup required: configure a private access key in config.php or MIGRATOR_ACCESS_KEY before connecting.'
        : 'Ready. Use HTTPS and enter your private access key to connect.';
      const missing = [];
      if (data.capabilities?.mysql === false) missing.push('PDO MySQL');
      if (data.capabilities?.wordpressRest === false) missing.push('cURL');
      if (missing.length) showError(`This server needs these PHP extensions enabled: ${missing.join(', ')}.`);
      try {
        saved = JSON.parse(localStorage.getItem(storageKey));
        if (saved?.version === 1 && saved.config) document.querySelector('#resume-notice').hidden = false;
      } catch {
        showError('Saved migration could not be read. Browser storage may be disabled.');
      }
    } catch (error) {
      document.querySelector('#startup').textContent = 'PHP backend unavailable. Upload all files to a PHP-enabled host; do not open index.html directly.';
      showError(error.message);
    }
  }
  initialize();
})();
