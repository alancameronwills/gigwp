(() => {
  const root = document.querySelector('.gigio-review');
  if (!root) return;
  const config = JSON.parse(root.dataset.config || '{}');
  const status = root.querySelector('.gigio-review-status');
  const list = root.querySelector('.gigio-review-list');
  const selectAll = root.querySelector('.gigio-review-all');
  const upload = root.querySelector('.gigio-review-upload');
  const importData = root.querySelector('.gigio-review-import-data');
  const importSave = root.querySelector('.gigio-review-import-save');
  let items = [];

  const request = async (path, options = {}) => {
    const response = await fetch(`${config.restBase}${path}`, {
      credentials: 'same-origin',
      ...options,
      headers: { 'X-WP-Nonce': config.nonce, ...(options.headers || {}) },
    });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || 'Request failed.');
    return body;
  };

  const esc = (value) => String(value || '').replace(/[&<>'"]/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
  })[char]);

  const displayDate = (value) => value ? value.replace('T', ' ') : 'Date needs checking';

  const updateControls = () => {
    const boxes = [...list.querySelectorAll('input[type="checkbox"]')];
    const selected = boxes.filter(box => box.checked).length;
    selectAll.checked = boxes.length > 0 && selected === boxes.length;
    selectAll.indeterminate = selected > 0 && selected < boxes.length;
    upload.disabled = selected === 0;
    upload.textContent = selected ? `Upload ${selected} selected event${selected === 1 ? '' : 's'}` : 'Upload selected events';
  };

  const render = () => {
    if (!items.length) {
      list.innerHTML = '<p class="gigio-review-empty">There are no events awaiting review.</p>';
      updateControls();
      return;
    }
    list.innerHTML = items.map(item => `
      <article class="gigio-review-card">
        <label class="gigio-review-choice"><input type="checkbox" value="${esc(item.id)}" aria-label="Select ${esc(item.title)}"></label>
        <a class="gigio-review-image" href="${esc(item.source_url || item.image_url)}" target="_blank" rel="noopener">
          ${item.image_url ? `<img src="${esc(item.image_url)}" alt="Poster for ${esc(item.title)}">` : '<span>No poster supplied</span>'}
        </a>
        <div class="gigio-review-details">
          <h3>${esc(item.title)}</h3>
          <p><strong>${esc(displayDate(item.dtstart))}</strong>${item.venue ? ` · ${esc(item.venue)}` : ''}</p>
          ${item.dtinfo ? `<p>${esc(item.dtinfo)}</p>` : ''}
          ${item.notes ? `<p class="gigio-review-notes">${esc(item.notes)}</p>` : ''}
          <p class="gigio-review-links">
            ${item.source_url ? `<a href="${esc(item.source_url)}" target="_blank" rel="noopener">View source</a>` : ''}
            ${item.bookinglink ? `<a href="${esc(item.bookinglink)}" target="_blank" rel="noopener">Booking / details</a>` : ''}
          </p>
        </div>
      </article>`).join('');
    list.querySelectorAll('input[type="checkbox"]').forEach(box => box.addEventListener('change', updateControls));
    list.querySelectorAll('img').forEach(image => image.addEventListener('error', () => {
      image.closest('.gigio-review-image').classList.add('gigio-review-no-image');
      image.remove();
    }));
    updateControls();
  };

  const load = async () => {
    try {
      const payload = await request('/review-queue');
      items = payload.items || [];
      status.textContent = items.length ? `${items.length} event${items.length === 1 ? '' : 's'} awaiting review.` : '';
      render();
    } catch (error) {
      status.textContent = `Could not load the review queue: ${error.message}`;
    }
  };

  selectAll.addEventListener('change', () => {
    list.querySelectorAll('input[type="checkbox"]').forEach(box => { box.checked = selectAll.checked; });
    updateControls();
  });

  upload.addEventListener('click', async () => {
    const ids = [...list.querySelectorAll('input[type="checkbox"]:checked')].map(box => box.value);
    if (!ids.length) return;
    if (!window.confirm(`Upload ${ids.length} selected event${ids.length === 1 ? '' : 's'} to the public Gigiau listings?`)) return;
    upload.disabled = true;
    status.textContent = 'Uploading selected events…';
    try {
      const result = await request('/review-queue/upload', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ids })
      });
      items = result.items || [];
      const created = result.created?.length || 0;
      const failed = result.failed || [];
      status.textContent = failed.length
        ? `${created} uploaded. ${failed.length} could not be uploaded: ${failed.map(item => item.title).join(', ')}.`
        : `${created} event${created === 1 ? '' : 's'} uploaded.`;
      render();
    } catch (error) {
      status.textContent = `Nothing was uploaded: ${error.message}`;
      updateControls();
    }
  });

  importSave?.addEventListener('click', async () => {
    let payload;
    try {
      payload = JSON.parse(importData.value);
      if (Array.isArray(payload)) payload = { items: payload };
      if (!Array.isArray(payload.items)) throw new Error('Expected an items array.');
    } catch (error) {
      status.textContent = `The scan data is not valid JSON: ${error.message}`;
      return;
    }
    importSave.disabled = true;
    status.textContent = 'Saving the review queue…';
    try {
      const result = await request('/review-queue', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
      });
      items = result.items || [];
      status.textContent = `${items.length} event${items.length === 1 ? '' : 's'} awaiting review.`;
      importData.value = '';
      render();
    } catch (error) {
      status.textContent = `Could not save the review queue: ${error.message}`;
    } finally {
      importSave.disabled = false;
    }
  });

  load();
})();
