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
  let furthestStep = 0;
  const connectionTabs = {};

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
    const wordpress = document.querySelector(side === 'source' ? '#source-wordpress' : '#destination-addresses');
    const panels = [transfer, database, wordpress];
    const tabs = element('div', { role: 'tablist', 'aria-label': `${side} connection details`, class: 'connection-tabs' });
    const buttons = [];
    function selectTab(index, focus = false) {
      panels.forEach((panel, position) => {
        panel.hidden = position !== index;
        buttons[position].setAttribute('aria-selected', String(position === index));
        buttons[position].tabIndex = position === index ? 0 : -1;
      });
      if (focus) buttons[index].focus();
    }
    panels.forEach((panel, index) => {
      const title = ['(S)FTP', 'Database', side === 'source' ? 'WordPress' : 'WordPress URLs'][index];
      const tab = element('button', {
        type: 'button', role: 'tab', id: `${side}-tab-${index}`,
        'aria-controls': `${side}-panel-${index}`
      }, title);
      panel.id = `${side}-panel-${index}`;
      panel.setAttribute('role', 'tabpanel');
      panel.setAttribute('aria-labelledby', tab.id);
      buttons.push(tab);
      tabs.append(tab);
      tab.addEventListener('click', () => { if (!busy && !running) selectTab(index); });
      tab.addEventListener('keydown', event => {
        const keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];
        if (!keys.includes(event.key) || busy || running) return;
        event.preventDefault();
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? panels.length - 1
          : (index + (event.key === 'ArrowRight' ? 1 : -1) + panels.length) % panels.length;
        selectTab(next, true);
      });
      if (index < panels.length - 1) {
        const next = element('button', { type: 'button', class: 'secondary' }, `Next: ${index === 0 ? 'Database' : 'WordPress'}`);
        next.addEventListener('click', () => {
          if (busy || running) return;
          const invalid = [...panel.querySelectorAll('input, select')].find(input => !input.checkValidity());
          if (invalid) invalid.reportValidity();
          else selectTab(index + 1, true);
        });
        panel.append(next);
      }
      root.append(panel);
    });
    root.prepend(tabs);
    connectionTabs[side] = { panels, selectTab };
    selectTab(0);
    configurationControls(side, root);
  }

  function updateNavigation() {
    document.querySelectorAll('[data-go-step]').forEach(button => {
      button.disabled = busy || running || Number(button.dataset.goStep) > furthestStep;
    });
  }

  function setStep(next) {
    step = next;
    furthestStep = Math.max(furthestStep, Math.min(step, 2));
    sections.forEach((section, index) => {
      section.hidden = index !== step;
      section.querySelectorAll('input, select').forEach(input => { input.disabled = index !== step; });
    });
    document.querySelectorAll('#steps li').forEach((item, index) => {
      if (index === step) item.setAttribute('aria-current', 'step');
      else item.removeAttribute('aria-current');
    });
    updateOptional();
    updateNavigation();
    sections[step].querySelector('h2').focus();
  }

  // Hidden tab fields still participate in validation; reveal the first invalid panel.
  form.addEventListener('invalid', event => {
    const first = [...sections[step].querySelectorAll('input, select')].find(input => !input.validity.valid && !input.disabled);
    if (event.target !== first) return;
    for (const { panels, selectTab } of Object.values(connectionTabs)) {
      const index = panels.findIndex(panel => panel.contains(event.target));
      if (index !== -1 && panels[index].hidden) selectTab(index);
    }
  }, true);

  function configurationControls(side, root) {
    const details = element('details', { class: 'configuration-package' });
    details.append(element('summary', {}, 'Import / export encrypted configuration'));
    details.append(element('p', { class: 'hint' }, 'Includes connection passwords. Use a unique passphrase of at least 12 characters and share it separately. The migrator access key, job state, and overwrite consent are never included.'));
    const passphrase = field(details, 'Package passphrase', '', 'password', '', false);
    passphrase.removeAttribute('name');
    passphrase.autocomplete = 'off';
    const file = field(details, 'Encrypted configuration file', '', 'file', '', false);
    file.removeAttribute('name');
    file.accept = '.json,application/json';
    const actions = element('div', { class: 'actions' });
    const exportButton = element('button', { type: 'button', class: 'secondary' }, `Export ${side}`);
    const importButton = element('button', { type: 'button', class: 'secondary' }, `Import ${side}`);
    const status = element('p', { role: 'status', class: 'hint' });
    actions.append(exportButton, importButton);
    details.append(actions, status);
    root.append(details);
    async function packageAction(importing) {
      if (busy || running) return;
      if (importing && checkpoint) {
        showError('Import is disabled for an existing migration checkpoint. Clear the saved migration and reload to configure a new migration.');
        return;
      }
      busy = true;
      updateNavigation();
      exportButton.disabled = importButton.disabled = true;
      showError('');
      status.textContent = importing ? 'Decrypting configuration…' : 'Encrypting configuration…';
      try {
        if (!window.ConfigPackage) throw new Error('Configuration encryption is unavailable. Upload all application files and use HTTPS.');
        if (importing) {
          const selected = file.files[0];
          if (!selected || selected.size > 131072) throw new Error('Choose an encrypted configuration file no larger than 128 KiB.');
          const settings = await window.ConfigPackage.decrypt(await selected.text(), side, passphrase.value);
          for (const input of form.querySelectorAll(`[name^="${side}."]`)) {
            const [, group, key] = input.name.split('.');
            input.value = settings[group][key];
          }
          if (side === 'source') {
            inspected = null;
            contentTypes([]);
            furthestStep = 0;
          } else {
            form.elements.namedItem('migration.homeUrl').value = settings.addresses.homeUrl;
            form.elements.namedItem('migration.siteUrl').value = settings.addresses.siteUrl;
            document.querySelector('#different-url').checked = settings.addresses.homeUrl !== settings.addresses.siteUrl;
            form.elements.namedItem('migration.confirmOverwrite').checked = false;
          }
          const fingerprint = form.elements.namedItem(`${side}.transfer.fingerprint`);
          const sftp = settings.transfer.protocol === 'sftp';
          fingerprint.parentElement.hidden = !sftp;
          fingerprint.required = sftp;
          updateOptional();
          connectionTabs[side].selectTab(0);
          status.textContent = 'Configuration imported. Review all tabs and reconnect before migrating.';
        } else {
          const settings = config();
          const selected = settings[side];
          if (side === 'destination') selected.addresses = {
            homeUrl: settings.migration.homeUrl, siteUrl: settings.migration.siteUrl
          };
          const encrypted = await window.ConfigPackage.encrypt(side, selected, passphrase.value);
          const url = URL.createObjectURL(new Blob([encrypted], { type: 'application/json' }));
          const link = element('a', { href: url, download: `saas-wordpress-${side}.json` });
          document.body.append(link);
          link.click();
          link.remove();
          setTimeout(() => URL.revokeObjectURL(url), 1000);
          status.textContent = 'Encrypted package downloaded. Keep the passphrase separate; it cannot be recovered.';
        }
      } catch (error) {
        status.textContent = '';
        showError(error.message);
      } finally {
        passphrase.value = '';
        file.value = '';
        busy = false;
        exportButton.disabled = importButton.disabled = false;
        updateNavigation();
      }
    }
    exportButton.addEventListener('click', () => packageAction(false));
    importButton.addEventListener('click', () => packageAction(true));
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
    updateNavigation();
    document.querySelector('#pause').hidden = true;
    document.querySelector('#retry').hidden = false;
    document.querySelector('#edit-connections').hidden = false;
    document.querySelector('#phase').textContent = message;
  }

  async function migrate() {
    if (running) return;
    running = true;
    updateNavigation();
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
    updateNavigation();
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
      updateNavigation();
      if (button) button.disabled = false;
    }
  });

  form.addEventListener('change', updateOptional);
  document.querySelectorAll('[data-go-step]').forEach(button => button.addEventListener('click', () => {
    const next = Number(button.dataset.goStep);
    if (!busy && !running && next <= furthestStep) setStep(next);
  }));
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
