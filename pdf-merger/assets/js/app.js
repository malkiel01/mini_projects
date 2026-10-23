(function () {
  'use strict';

  pdfjsLib.GlobalWorkerOptions.workerSrc = 'assets/vendor/pdf.worker.min.js';

  var files = [];
  var currentStep = 1;
  var fileIdCounter = 0;
  var dragSrcIndex = null;
  var lastMergedBlob = null;
  var lastMergedName = '';

  var $ = function (id) { return document.getElementById(id); };

  var $dropZone       = $('drop-zone');
  var $fileInput      = $('file-input');
  var $fileList       = $('file-list');
  var $totalPages     = $('total-pages');
  var $totalFiles     = $('total-files');
  var $fileStats      = $('file-stats');
  var $step1          = $('step-1');
  var $step2          = $('step-2');
  var $step3          = $('step-3');
  var $ind1           = $('step-indicator-1');
  var $ind2           = $('step-indicator-2');
  var $ind3           = $('step-indicator-3');
  var $line1          = $('step-line-1');
  var $line2          = $('step-line-2');
  var $btnToStep2     = $('btn-to-step-2');
  var $btnToStep1     = $('btn-to-step-1');
  var $btnMerge       = $('btn-merge');
  var $btnToStep2Back = $('btn-to-step-2-back');
  var $step1Actions   = $('step-1-actions');
  var $outputFilename = $('output-filename');
  var $progressBar    = $('progress-bar');
  var $progressText   = $('progress-text');
  var $progressMsg    = $('progress-message');
  var $progressContainer = $('progress-container');
  var $exportSuccess  = $('export-success');
  var $backRow        = $('back-to-settings-row');
  var $btnDownloadAgain = $('btn-download-again');
  var $btnNewMerge    = $('btn-new-merge');
  var $previewModal   = $('preview-modal');
  var $previewContent = $('preview-content');
  var $previewTitle   = $('preview-title');
  var $previewClose   = $('preview-close');
  var $toastContainer = $('toast-container');
  var $margin         = $('opt-margin');
  var $mergeResultMeta = $('merge-result-meta');

  var PAGE_SIZES = {
    a4:       { width: 595.28, height: 841.89 },
    letter:   { width: 612,    height: 792 },
    legal:    { width: 612,    height: 1008 },
    original: null,
  };
  var MM_TO_PT = 72 / 25.4;

  function isPdfFile(file) { return file.type === 'application/pdf' || /\.pdf$/i.test(file.name); }
  function isImageFile(file) {
    return (file.type || '').indexOf('image/') === 0 || /\.(jpe?g|png|gif|bmp|webp|avif)$/i.test(file.name);
  }

  /** מנקה שם קובץ מתווים אסורים; ריק → ברירת המחדל. */
  function safeName(raw, fallback) {
    var clean = String(raw || '').trim().replace(/[\\/:*?"<>|]/g, '_');
    return clean || fallback;
  }

  function formatBytes(n) {
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return Math.round(n / 1024) + ' KB';
    return (n / 1024 / 1024).toFixed(1) + ' MB';
  }

  function pagesLabel(n) { return n === 1 ? 'עמוד אחד' : n + ' עמודים'; }

  /** "שם · 6 עמודים · 64 KB" — כל פיסה ב-<bdi>, אחרת "64 KB" מתהפך בתוך שורה עברית. */
  function setBidiText(el, parts) {
    el.textContent = '';
    parts.forEach(function (part, i) {
      if (i) el.appendChild(document.createTextNode(' · '));
      var bdi = document.createElement('bdi');
      bdi.textContent = part;
      el.appendChild(bdi);
    });
  }

  /** pdf.js מדווח על קובץ נעול ב-PasswordException — מתרגמים להודעה ברורה. */
  function pdfErrorText(err) {
    return err && err.name === 'PasswordException' ? 'הקובץ מוגן בסיסמה' : (err && err.message) || 'לא ניתן לקרוא את הקובץ';
  }
  var QUALITY_MAP = { high: 0.95, medium: 0.8, low: 0.6 };

  function getRadioValue(name) {
    var el = document.querySelector('input[name="' + name + '"]:checked');
    return el ? el.value : null;
  }

  // ── Toast ──────────────────────────────────────────
  function showToast(message, type) {
    type = type || 'info';
    var toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.textContent = message;
    $toastContainer.appendChild(toast);
    setTimeout(function () {
      toast.classList.add('hiding');
      setTimeout(function () { toast.remove(); }, 300);
    }, 3500);
  }

  // ── Progress ───────────────────────────────────────
  function updateProgress(percent, message) {
    $progressBar.style.width = percent + '%';
    $progressText.textContent = Math.round(percent) + '%';
    if (message) $progressMsg.textContent = message;
  }

  // ── Step navigation ────────────────────────────────
  function goToStep(step) {
    currentStep = step;
    var panels = [$step1, $step2, $step3];
    var indicators = [$ind1, $ind2, $ind3];
    var lines = [$line1, $line2];

    panels.forEach(function (p, i) {
      if (i + 1 === step) {
        p.classList.add('visible');
      } else {
        p.classList.remove('visible');
      }
    });

    indicators.forEach(function (ind, i) {
      var s = i + 1;
      ind.classList.remove('active', 'done');
      if (s === step) ind.classList.add('active');
      else if (s < step) ind.classList.add('done');
    });

    lines.forEach(function (line, i) {
      var s = i + 1;
      line.classList.remove('active', 'done');
      if (s < step) line.classList.add('done');
      else if (s === step) line.classList.add('active');
    });
  }

  // ── File helpers ───────────────────────────────────
  function isPdfEntry(f) { return f.type === 'application/pdf'; }

  function updateTotalPageCount() {
    var pages = 0;
    files.forEach(function (f) { pages += f.pageCount; });
    $totalPages.textContent = pages;
    $totalFiles.textContent = files.length;
    $fileStats.style.display = files.length > 0 ? '' : 'none';
    $step1Actions.style.display = files.length > 0 ? '' : 'none';
  }

  // ── Thumbnails ─────────────────────────────────────
  // pdf.js מעביר את ה-buffer ל-worker ומרוקן אותו, לכן תמיד מעבירים עותק
  function openPdfJs(arrayBuffer) {
    return pdfjsLib.getDocument({ data: new Uint8Array(arrayBuffer.slice(0)) }).promise;
  }

  function generatePdfThumbnail(pdf, pageIndex) {
    return pdf.getPage((pageIndex || 0) + 1).then(function (page) {
      var vp = page.getViewport({ scale: 1 });
      var scale = 150 / Math.max(vp.width, vp.height);
      var scaled = page.getViewport({ scale: scale });
      var canvas = document.createElement('canvas');
      canvas.width = scaled.width;
      canvas.height = scaled.height;
      return page.render({ canvasContext: canvas.getContext('2d'), viewport: scaled }).promise.then(function () {
        return canvas.toDataURL('image/png');
      });
    });
  }

  function generateImageThumbnail(arrayBuffer, mimeType) {
    return new Promise(function (resolve, reject) {
      var blob = new Blob([arrayBuffer], { type: mimeType });
      var url = URL.createObjectURL(blob);
      var img = new Image();
      img.onload = function () {
        var max = 150;
        var ratio = Math.min(max / img.width, max / img.height, 1);
        var canvas = document.createElement('canvas');
        canvas.width = img.width * ratio;
        canvas.height = img.height * ratio;
        canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
        URL.revokeObjectURL(url);
        resolve(canvas.toDataURL('image/png'));
      };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('שגיאה בטעינת תמונה')); };
      img.src = url;
    });
  }

  // ── Add / remove files ─────────────────────────────
  async function addFiles(fileList) {
    fileList = Array.from(fileList);
    for (var i = 0; i < fileList.length; i++) {
      var file = fileList[i];
      var type = isPdfFile(file) ? 'application/pdf' : (isImageFile(file) ? (file.type || 'image/*') : '');
      if (!type) {
        showToast('סוג קובץ לא נתמך: ' + file.name, 'error');
        continue;
      }
      try {
        var buf = await file.arrayBuffer();
        var entry = {
          id: ++fileIdCounter,
          name: file.name,
          type: type,
          data: buf,
          pageCount: 1,
          thumbnail: '',
          rotation: 0,
        };

        if (entry.type === 'application/pdf') {
          var pdfDoc = await openPdfJs(buf);
          entry.pageCount = pdfDoc.numPages;
          entry.rotation = new Array(pdfDoc.numPages).fill(0);
          entry.thumbnail = await generatePdfThumbnail(pdfDoc, 0);
          pdfDoc.destroy();
        } else {
          entry.thumbnail = await generateImageThumbnail(buf, type);
        }
        files.push(entry);
      } catch (err) {
        showToast('שגיאה בקריאת ' + file.name + ': ' + pdfErrorText(err), 'error');
      }
    }
    updateTotalPageCount();
    renderFileList();
  }

  function removeFile(id) {
    files = files.filter(function (f) { return f.id !== id; });
    updateTotalPageCount();
    renderFileList();
  }

  // ── Render file list ───────────────────────────────
  function renderFileList() {
    $fileList.innerHTML = '';
    $btnToStep2.disabled = files.length === 0;

    files.forEach(function (f, idx) {
      var li = document.createElement('li');
      li.className = 'file-card';
      li.setAttribute('draggable', 'true');
      li.dataset.index = idx;

      // drag handle
      var handle = document.createElement('div');
      handle.className = 'file-handle';
      handle.innerHTML = '<span></span><span></span><span></span>';

      // thumbnail
      var thumb = document.createElement('img');
      thumb.className = 'file-thumb';
      thumb.src = f.thumbnail;
      thumb.alt = f.name;
      if (typeof f.rotation === 'number' && f.rotation !== 0) {
        thumb.style.transform = 'rotate(' + f.rotation + 'deg)';
      }
      thumb.addEventListener('click', function () {
        showPreview(f.id);
      });

      // info
      var info = document.createElement('div');
      info.className = 'file-info';
      var nameEl = document.createElement('p');
      nameEl.className = 'file-name';
      nameEl.textContent = f.name;
      nameEl.title = f.name;
      var meta = document.createElement('p');
      meta.className = 'file-meta';
      meta.textContent = f.pageCount + (f.pageCount === 1 ? ' עמוד' : ' עמודים');
      info.appendChild(nameEl);
      info.appendChild(meta);

      // actions
      var actions = document.createElement('div');
      actions.className = 'file-actions';

      if (isPdfEntry(f) && f.pageCount > 1) {
        actions.appendChild(makeBtn('תצוגה מקדימה', '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>', function () { showPreview(f.id); }));
        actions.appendChild(makeBtn('פיצול לדפי PDF', '<path d="M16 3h5v5"/><path d="M8 3H3v5"/><path d="M12 22V8"/><path d="M21 3l-9 9"/><path d="M3 3l9 9"/>', function () { splitPdf(f.id); }));
      }

      var upBtn = makeBtn('הזזה למעלה', '<path d="M12 19V5m0 0-6 6m6-6 6 6"/>', function () { moveFile(f.id, -1); });
      upBtn.disabled = idx === 0;
      var downBtn = makeBtn('הזזה למטה', '<path d="M12 5v14m0 0 6-6m-6 6-6-6"/>', function () { moveFile(f.id, 1); });
      downBtn.disabled = idx === files.length - 1;
      actions.appendChild(upBtn);
      actions.appendChild(downBtn);

      if (!isPdfEntry(f)) {
        actions.appendChild(makeBtn('סיבוב', '<path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>', function () { rotateFile(f.id, 90); }));
      }

      var delBtn = makeBtn('הסרה', '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>', function () { removeFile(f.id); });
      delBtn.classList.add('danger');
      actions.appendChild(delBtn);

      li.appendChild(handle);
      li.appendChild(thumb);
      li.appendChild(info);
      li.appendChild(actions);

      li.addEventListener('dragstart', onDragStart);
      li.addEventListener('dragover', onDragOver);
      li.addEventListener('dragend', onDragEnd);
      li.addEventListener('drop', onDrop);
      li.addEventListener('pointerdown', onPointerDown);

      $fileList.appendChild(li);
    });
  }

  function makeBtn(title, svgInner, onClick) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'file-btn';
    btn.title = title;
    btn.setAttribute('aria-label', title);
    btn.innerHTML = '<svg viewBox="0 0 24 24">' + svgInner + '</svg>';
    btn.addEventListener('click', onClick);
    return btn;
  }

  // ── Drag reorder (desktop) ─────────────────────────
  function onDragStart(e) {
    dragSrcIndex = +this.dataset.index;
    this.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', '' + dragSrcIndex);
  }

  function onDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    var card = e.currentTarget;
    var rect = card.getBoundingClientRect();
    var midY = rect.top + rect.height / 2;
    card.classList.remove('drag-over');
    card.classList.add('drag-over');
  }

  function onDragEnd() {
    this.classList.remove('dragging');
    document.querySelectorAll('.file-card').forEach(function (c) {
      c.classList.remove('drag-over');
    });
  }

  function onDrop(e) {
    e.preventDefault();
    var targetIndex = +this.dataset.index;
    if (dragSrcIndex === null || dragSrcIndex === targetIndex) return;
    var moved = files.splice(dragSrcIndex, 1)[0];
    files.splice(targetIndex, 0, moved);
    dragSrcIndex = null;
    renderFileList();
  }

  // ── Touch reorder ──────────────────────────────────
  var pointerState = null;

  function onPointerDown(e) {
    if (!e.target.closest('.file-handle')) return;
    if (e.pointerType === 'mouse') return;

    var card = e.currentTarget;
    var idx = +card.dataset.index;
    pointerState = { index: idx, startY: e.clientY, moved: false, targetIndex: null };
    card.setPointerCapture(e.pointerId);

    var onMove = function (ev) {
      if (!pointerState) return;
      var dy = ev.clientY - pointerState.startY;
      if (Math.abs(dy) > 10) pointerState.moved = true;
      if (!pointerState.moved) return;
      card.style.transform = 'translateY(' + dy + 'px)';
      card.style.zIndex = '100';
      card.style.opacity = '0.8';

      var cards = Array.from($fileList.querySelectorAll('.file-card'));
      cards.forEach(function (c) { c.classList.remove('drag-over'); });
      for (var ci = 0; ci < cards.length; ci++) {
        if (ci === pointerState.index) continue;
        var r = cards[ci].getBoundingClientRect();
        if (ev.clientY > r.top && ev.clientY < r.bottom) {
          cards[ci].classList.add('drag-over');
          pointerState.targetIndex = ci;
          break;
        }
      }
    };

    var onUp = function () {
      card.removeEventListener('pointermove', onMove);
      card.removeEventListener('pointerup', onUp);
      card.removeEventListener('pointercancel', onUp);
      card.style.transform = '';
      card.style.zIndex = '';
      card.style.opacity = '';

      if (pointerState && pointerState.moved && pointerState.targetIndex != null
          && pointerState.targetIndex !== pointerState.index) {
        var moved = files.splice(pointerState.index, 1)[0];
        files.splice(pointerState.targetIndex, 0, moved);
        renderFileList();
      } else {
        document.querySelectorAll('.file-card').forEach(function (c) {
          c.classList.remove('drag-over');
        });
      }
      pointerState = null;
    };

    card.addEventListener('pointermove', onMove);
    card.addEventListener('pointerup', onUp);
    card.addEventListener('pointercancel', onUp);
  }

  // ── Order ──────────────────────────────────────────
  function moveFile(id, delta) {
    var i = files.findIndex(function (x) { return x.id === id; });
    var j = i + delta;
    if (i < 0 || j < 0 || j >= files.length) return;
    var t = files[i]; files[i] = files[j]; files[j] = t;
    renderFileList();
  }

  // מיון "טבעי" — img2 לפני img10
  function sortFilesByName() {
    files.sort(function (a, b) {
      return a.name.localeCompare(b.name, 'he', { numeric: true, sensitivity: 'base' });
    });
    renderFileList();
  }

  // ── Rotation ───────────────────────────────────────
  function rotateFile(id, angle) {
    var f = files.find(function (x) { return x.id === id; });
    if (!f) return;
    f.rotation = ((f.rotation || 0) + angle) % 360;
    renderFileList();
  }

  function rotatePage(fileId, pageIndex, angle) {
    var f = files.find(function (x) { return x.id === fileId; });
    if (!f || !Array.isArray(f.rotation)) return;
    f.rotation[pageIndex] = ((f.rotation[pageIndex] || 0) + angle) % 360;
  }

  // ── Preview modal ──────────────────────────────────
  async function showPreview(fileId) {
    var f = files.find(function (x) { return x.id === fileId; });
    if (!f) return;

    $previewTitle.textContent = f.name;
    $previewContent.innerHTML = '<p class="text-center text-muted">טוען תצוגה מקדימה...</p>';
    $previewModal.classList.add('open');

    try {
      if (isPdfEntry(f)) {
        var pdf = await openPdfJs(f.data);
        $previewContent.innerHTML = '';
        var grid = document.createElement('div');
        grid.className = 'page-grid';

        for (var pi = 0; pi < pdf.numPages; pi++) {
          await renderPreviewPage(f, pdf, pi, grid);
        }
        $previewContent.appendChild(grid);
      } else {
        $previewContent.innerHTML = '';
        var img = document.createElement('img');
        img.src = f.thumbnail;
        img.style.maxWidth = '100%';
        img.style.margin = '0 auto';
        if (typeof f.rotation === 'number') {
          img.style.transform = 'rotate(' + f.rotation + 'deg)';
        }
        $previewContent.appendChild(img);
      }
    } catch (err) {
      $previewContent.innerHTML = '<p style="color:var(--danger)">שגיאה: ' + err.message + '</p>';
    }
  }

  async function renderPreviewPage(fileEntry, pdfDoc, pageIndex, container) {
    var page = await pdfDoc.getPage(pageIndex + 1);
    var vp = page.getViewport({ scale: 1 });
    var scale = 200 / Math.max(vp.width, vp.height);
    var scaled = page.getViewport({ scale: scale });

    var wrapper = document.createElement('div');
    wrapper.className = 'page-thumb';

    var canvas = document.createElement('canvas');
    canvas.width = scaled.width;
    canvas.height = scaled.height;
    await page.render({ canvasContext: canvas.getContext('2d'), viewport: scaled }).promise;

    var rot = Array.isArray(fileEntry.rotation) ? fileEntry.rotation[pageIndex] : 0;
    if (rot) canvas.style.transform = 'rotate(' + rot + 'deg)';

    var num = document.createElement('span');
    num.className = 'page-num';
    num.textContent = pageIndex + 1;

    var rotBtn = document.createElement('button');
    rotBtn.className = 'page-rotate-btn';
    rotBtn.title = 'סיבוב';
    rotBtn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>';
    (function (fi, pi, cv) {
      rotBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        rotatePage(fi, pi, 90);
        var r = Array.isArray(fileEntry.rotation) ? fileEntry.rotation[pi] : 0;
        cv.style.transform = 'rotate(' + r + 'deg)';
      });
    })(fileEntry.id, pageIndex, canvas);

    wrapper.appendChild(canvas);
    wrapper.appendChild(num);
    wrapper.appendChild(rotBtn);
    container.appendChild(wrapper);
  }

  function closePreview() {
    $previewModal.classList.remove('open');
    $previewContent.innerHTML = '';
  }

  // ── WEBP / GIF / BMP / AVIF to PNG ─────────────────
  // pdf-lib מטמיע רק JPG ו-PNG; את השאר הדפדפן מפענח ואנחנו מטמיעים כ-PNG
  function imageToPng(arrayBuffer, mimeType) {
    return new Promise(function (resolve, reject) {
      var blob = new Blob([arrayBuffer], { type: mimeType });
      var url = URL.createObjectURL(blob);
      var img = new Image();
      img.onload = function () {
        var canvas = document.createElement('canvas');
        canvas.width = img.width;
        canvas.height = img.height;
        canvas.getContext('2d').drawImage(img, 0, 0);
        URL.revokeObjectURL(url);
        canvas.toBlob(function (b) {
          if (!b) { reject(new Error('המרת התמונה נכשלה')); return; }
          b.arrayBuffer().then(resolve).catch(reject);
        }, 'image/png');
      };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('הדפדפן לא מצליח לפענח את התמונה')); };
      img.src = url;
    });
  }

  // ── Re-encode JPEG ─────────────────────────────────
  function reEncodeJpeg(arrayBuffer, quality) {
    return new Promise(function (resolve, reject) {
      var blob = new Blob([arrayBuffer], { type: 'image/jpeg' });
      var url = URL.createObjectURL(blob);
      var img = new Image();
      img.onload = function () {
        var canvas = document.createElement('canvas');
        canvas.width = img.width;
        canvas.height = img.height;
        canvas.getContext('2d').drawImage(img, 0, 0);
        URL.revokeObjectURL(url);
        canvas.toBlob(function (b) {
          if (!b) { reject(new Error('שגיאה בדחיסת תמונה')); return; }
          b.arrayBuffer().then(resolve).catch(reject);
        }, 'image/jpeg', quality);
      };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('שגיאה בטעינת תמונה')); };
      img.src = url;
    });
  }

  // ── Merge PDF ──────────────────────────────────────
  async function mergePdf() {
    if (files.length === 0) {
      showToast('אין קבצים לאיחוד', 'error');
      return;
    }

    goToStep(3);
    $progressContainer.style.display = '';
    $exportSuccess.style.display = 'none';
    $backRow.style.display = 'none';
    $btnMerge.disabled = true;
    updateProgress(0, 'מתחיל איחוד...');

    var pageSizeKey = getRadioValue('page-size') || 'original';
    var orientKey   = getRadioValue('orientation') || 'auto';
    var qualityKey  = getRadioValue('image-quality') || 'high';
    var quality     = QUALITY_MAP[qualityKey] || 0.95;
    var marginPt    = pageSizeKey === 'original' ? 0 : Math.min(50, Math.max(0, Number($margin.value) || 0)) * MM_TO_PT;
    var outputName  = safeName(($outputFilename.value || '').replace(/\.pdf$/i, ''), 'merged') + '.pdf';
    var failures    = [];

    try {
      var mergedPdf = await PDFLib.PDFDocument.create();
      var total = files.length;

      for (var i = 0; i < total; i++) {
        var f = files[i];
        updateProgress((i / total) * 90, 'מעבד: ' + f.name);

        // קובץ אחד פגום לא מפיל את כל האיחוד — מדלגים עליו ומדווחים בסוף
        try {
          if (isPdfEntry(f)) {
            await addPdfToMerged(mergedPdf, f, pageSizeKey, orientKey);
          } else {
            await addImageToMerged(mergedPdf, f, pageSizeKey, orientKey, quality, marginPt);
          }
        } catch (fileErr) {
          failures.push(f.name + ' — ' + pdfErrorText(fileErr));
        }
      }

      if (mergedPdf.getPageCount() === 0) throw new Error(failures.join(' · ') || 'לא נוצר אף עמוד');

      updateProgress(95, 'שומר PDF...');
      var pdfBytes = await mergedPdf.save();
      var blob = new Blob([pdfBytes], { type: 'application/pdf' });

      lastMergedBlob = blob;
      lastMergedName = outputName;
      triggerDownload(blob, outputName);

      updateProgress(100, 'הושלם!');
      $progressContainer.style.display = 'none';
      setBidiText($mergeResultMeta, [outputName, pagesLabel(mergedPdf.getPageCount()), formatBytes(blob.size)]);
      $exportSuccess.style.display = '';
      if (failures.length) showToast('דילגתי על ' + failures.length + ': ' + failures.join(' · '), 'warning');
      else showToast('הקובץ המאוחד הורד בהצלחה', 'success');
    } catch (err) {
      showToast('שגיאה באיחוד: ' + err.message, 'error');
      updateProgress(0, '');
      $backRow.style.display = '';
    } finally {
      $btnMerge.disabled = false;
    }
  }

  async function addPdfToMerged(mergedPdf, fileEntry, pageSizeKey, orientKey) {
    var srcDoc = await PDFLib.PDFDocument.load(fileEntry.data, { ignoreEncryption: true });
    var indices = [];
    for (var k = 0; k < srcDoc.getPageCount(); k++) indices.push(k);
    var copiedPages = await mergedPdf.copyPages(srcDoc, indices);

    copiedPages.forEach(function (page, idx) {
      var rot = Array.isArray(fileEntry.rotation) ? (fileEntry.rotation[idx] || 0) : 0;
      if (rot) {
        var current = page.getRotation().angle || 0;
        page.setRotation(PDFLib.degrees(current + rot));
      }

      if (pageSizeKey !== 'original') {
        var dims = PAGE_SIZES[pageSizeKey];
        var w = dims.width, h = dims.height;
        if (orientKey === 'landscape' && h > w) { var t = w; w = h; h = t; }
        else if (orientKey === 'portrait' && w > h) { var t2 = w; w = h; h = t2; }
        page.setSize(w, h);
      } else if (orientKey !== 'auto') {
        var size = page.getSize();
        var isLand = size.width > size.height;
        if ((orientKey === 'landscape' && !isLand) || (orientKey === 'portrait' && isLand)) {
          page.setSize(size.height, size.width);
        }
      }

      mergedPdf.addPage(page);
    });
  }

  async function addImageToMerged(mergedPdf, fileEntry, pageSizeKey, orientKey, quality, marginPt) {
    var imgData = fileEntry.data;
    var imgType = fileEntry.type;

    if (imgType !== 'image/jpeg' && imgType !== 'image/png') {
      imgData = await imageToPng(imgData, imgType);
      imgType = 'image/png';
    }

    if (imgType === 'image/jpeg' && quality < 0.95) {
      imgData = await reEncodeJpeg(imgData, quality);
    }

    var embeddedImg;
    if (imgType === 'image/jpeg') {
      embeddedImg = await mergedPdf.embedJpg(imgData);
    } else {
      embeddedImg = await mergedPdf.embedPng(imgData);
    }

    var imgW = embeddedImg.width;
    var imgH = embeddedImg.height;
    var rotation = (typeof fileEntry.rotation === 'number') ? fileEntry.rotation : 0;

    var effectiveW = imgW, effectiveH = imgH;
    if (rotation === 90 || rotation === 270) {
      effectiveW = imgH;
      effectiveH = imgW;
    }

    var pageW, pageH;
    if (pageSizeKey === 'original') {
      pageW = effectiveW;
      pageH = effectiveH;
    } else {
      var dims = PAGE_SIZES[pageSizeKey];
      pageW = dims.width;
      pageH = dims.height;
    }

    if (orientKey === 'landscape') {
      if (pageH > pageW) { var t = pageW; pageW = pageH; pageH = t; }
    } else if (orientKey === 'portrait') {
      if (pageW > pageH) { var t2 = pageW; pageW = pageH; pageH = t2; }
    } else if (orientKey === 'auto' && pageSizeKey !== 'original') {
      if ((effectiveW > effectiveH) !== (pageW > pageH)) {
        var t3 = pageW; pageW = pageH; pageH = t3;
      }
    }

    var page = mergedPdf.addPage([pageW, pageH]);
    // שוליים (רק בגודל דף קבוע): התמונה נכנסת למלבן שבתוך השוליים, ממורכזת
    var m = marginPt || 0;
    var availW = Math.max(1, pageW - 2 * m);
    var availH = Math.max(1, pageH - 2 * m);
    var scale = Math.min(availW / effectiveW, availH / effectiveH, 1);
    var drawW = imgW * scale;
    var drawH = imgH * scale;

    if (rotation === 0) {
      page.drawImage(embeddedImg, {
        x: (pageW - drawW) / 2,
        y: (pageH - drawH) / 2,
        width: drawW,
        height: drawH,
      });
    } else {
      var cx = pageW / 2;
      var cy = pageH / 2;
      var rad = (rotation * Math.PI) / 180;

      page.pushOperators(
        PDFLib.pushGraphicsState(),
        PDFLib.concatTransformationMatrix(1, 0, 0, 1, cx, cy),
        PDFLib.concatTransformationMatrix(
          Math.cos(rad), Math.sin(rad),
          -Math.sin(rad), Math.cos(rad),
          0, 0
        ),
        PDFLib.concatTransformationMatrix(1, 0, 0, 1, -drawW / 2, -drawH / 2)
      );

      page.drawImage(embeddedImg, { x: 0, y: 0, width: drawW, height: drawH });
      page.pushOperators(PDFLib.popGraphicsState());
    }
  }

  // ── Split PDF ──────────────────────────────────────
  async function splitPdf(fileId) {
    var f = files.find(function (x) { return x.id === fileId; });
    if (!f || !isPdfEntry(f)) return;

    showToast('מפצל את ' + f.name + '...', 'info');

    try {
      var srcDoc = await PDFLib.PDFDocument.load(f.data);
      var count = srcDoc.getPageCount();
      if (count <= 1) { showToast('הקובץ מכיל עמוד אחד בלבד', 'info'); return; }

      var baseName = f.name.replace(/\.pdf$/i, '');
      var zipFiles = [];

      for (var pi = 0; pi < count; pi++) {
        var newDoc = await PDFLib.PDFDocument.create();
        var copied = await newDoc.copyPages(srcDoc, [pi]);
        newDoc.addPage(copied[0]);
        var bytes = await newDoc.save();
        zipFiles.push({ name: baseName + '_page_' + (pi + 1) + '.pdf', data: new Uint8Array(bytes) });
      }

      triggerDownload(buildZip(zipFiles), baseName + '_pages.zip');
      showToast('הפיצול הושלם — ' + count + ' קבצים', 'success');
    } catch (err) {
      showToast('שגיאה בפיצול: ' + err.message, 'error');
    }
  }

  // ── Minimal ZIP ────────────────────────────────────
  function buildZip(entries) {
    var localHeaders = [];
    var centralHeaders = [];
    var offset = 0;

    entries.forEach(function (entry) {
      var nameBytes = new TextEncoder().encode(entry.name);
      var dataLen = entry.data.length;
      var crc = crc32(entry.data);

      var local = new Uint8Array(30 + nameBytes.length + dataLen);
      var lv = new DataView(local.buffer);
      lv.setUint32(0, 0x04034b50, true);
      lv.setUint16(4, 20, true);
      lv.setUint16(8, 0, true);
      lv.setUint32(14, crc, true);
      lv.setUint32(18, dataLen, true);
      lv.setUint32(22, dataLen, true);
      lv.setUint16(26, nameBytes.length, true);
      local.set(nameBytes, 30);
      local.set(entry.data, 30 + nameBytes.length);
      localHeaders.push(local);

      var central = new Uint8Array(46 + nameBytes.length);
      var cv = new DataView(central.buffer);
      cv.setUint32(0, 0x02014b50, true);
      cv.setUint16(4, 20, true);
      cv.setUint16(6, 20, true);
      cv.setUint32(16, crc, true);
      cv.setUint32(20, dataLen, true);
      cv.setUint32(24, dataLen, true);
      cv.setUint16(28, nameBytes.length, true);
      cv.setUint32(42, offset, true);
      central.set(nameBytes, 46);
      centralHeaders.push(central);

      offset += local.length;
    });

    var centralSize = 0;
    centralHeaders.forEach(function (c) { centralSize += c.length; });

    var eocd = new Uint8Array(22);
    var ev = new DataView(eocd.buffer);
    ev.setUint32(0, 0x06054b50, true);
    ev.setUint16(8, entries.length, true);
    ev.setUint16(10, entries.length, true);
    ev.setUint32(12, centralSize, true);
    ev.setUint32(16, offset, true);

    var zip = new Uint8Array(offset + centralSize + 22);
    var pos = 0;
    localHeaders.forEach(function (l) { zip.set(l, pos); pos += l.length; });
    centralHeaders.forEach(function (c) { zip.set(c, pos); pos += c.length; });
    zip.set(eocd, pos);

    return new Blob([zip], { type: 'application/zip' });
  }

  var crc32Table = null;
  function crc32(data) {
    if (!crc32Table) {
      crc32Table = new Uint32Array(256);
      for (var i = 0; i < 256; i++) {
        var c = i;
        for (var j = 0; j < 8; j++) c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
        crc32Table[i] = c;
      }
    }
    var crc = 0xFFFFFFFF;
    for (var i = 0; i < data.length; i++) crc = crc32Table[(crc ^ data[i]) & 0xFF] ^ (crc >>> 8);
    return (crc ^ 0xFFFFFFFF) >>> 0;
  }

  // ── Download ───────────────────────────────────────
  function triggerDownload(blob, filename) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(function () { URL.revokeObjectURL(url); }, 5000);
  }

  // ══ פיצול PDF לתמונות בתוך ZIP ══════════════════════
  var splitFiles = [];
  var lastSplitBlob = null;
  var lastSplitName = '';
  var SPLIT_EXT = { 'image/png': 'png', 'image/jpeg': 'jpg', 'image/webp': 'webp' };

  /**
   * מפרש טווח עמודים בסגנון "1-3, 7, 10-" למספרי עמודים (מ-1, ממוינים, בלי כפילויות).
   * ריק → כל העמודים. עמודים שמעבר לסוף הקובץ נחתכים בשקט.
   */
  function parsePageRange(spec, total) {
    var text = String(spec || '').trim();
    var all = [];
    if (!text) { for (var a = 1; a <= total; a++) all.push(a); return all; }

    var wanted = {};
    text.split(/[,،]/).forEach(function (chunk) {
      var part = chunk.trim();
      if (!part) return;
      var m = part.match(/^(\d+)?\s*([-–])?\s*(\d+)?$/);
      if (!m || (!m[1] && !m[3])) throw new Error('טווח עמודים לא תקין: "' + part + '"');
      var from = m[1] ? Number(m[1]) : 1;
      var to = m[2] ? (m[3] ? Number(m[3]) : total) : from;
      // "10-" בקובץ של 3 עמודים אינו שגיאה — פשוט אין בו עמודים מהטווח הזה
      if (m[3] && from > to) throw new Error('טווח עמודים הפוך: "' + part + '"');
      for (var p = Math.max(1, from); p <= Math.min(total, to); p++) wanted[p] = true;
    });
    var pages = Object.keys(wanted).map(Number).sort(function (x, y) { return x - y; });
    if (!pages.length) throw new Error('הטווח לא כולל אף עמוד קיים');
    return pages;
  }

  function updateSplitStats() {
    var pages = 0;
    splitFiles.forEach(function (f) { pages += f.pageCount || 0; });
    $('split-files').textContent = splitFiles.length;
    $('split-pages').textContent = pages;
    $('split-stats').hidden = splitFiles.length === 0;
    $('split-options').hidden = splitFiles.length === 0;
  }

  function renderSplitList() {
    var $list = $('split-list');
    $list.innerHTML = '';
    splitFiles.forEach(function (f) {
      var li = document.createElement('li');
      li.className = 'file-card compact';

      var thumb = document.createElement('img');
      thumb.className = 'file-thumb';
      thumb.alt = '';
      if (f.thumbnail) thumb.src = f.thumbnail;

      var info = document.createElement('div');
      info.className = 'file-info';
      var nameEl = document.createElement('p');
      nameEl.className = 'file-name';
      nameEl.textContent = f.name;
      nameEl.title = f.name;
      var meta = document.createElement('p');
      meta.className = 'file-meta' + (f.error ? ' bad' : '');
      if (f.error) meta.textContent = f.error;
      else setBidiText(meta, [pagesLabel(f.pageCount), formatBytes(f.data.byteLength)]);
      info.appendChild(nameEl);
      info.appendChild(meta);

      var actions = document.createElement('div');
      actions.className = 'file-actions';
      var del = makeBtn('הסרה', '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>', function () {
        splitFiles = splitFiles.filter(function (x) { return x.id !== f.id; });
        renderSplitList();
      });
      del.classList.add('danger');
      actions.appendChild(del);

      li.appendChild(thumb);
      li.appendChild(info);
      li.appendChild(actions);
      $list.appendChild(li);
    });
    updateSplitStats();
  }

  async function addSplitFiles(list) {
    list = Array.from(list);
    for (var i = 0; i < list.length; i++) {
      var file = list[i];
      if (!isPdfFile(file)) { showToast('לפיצול אפשר לבחור קבצי PDF בלבד: ' + file.name, 'error'); continue; }
      var entry = { id: ++fileIdCounter, name: file.name, data: await file.arrayBuffer(), pageCount: 0, thumbnail: '', error: '' };
      try {
        var doc = await openPdfJs(entry.data);
        entry.pageCount = doc.numPages;
        entry.thumbnail = await generatePdfThumbnail(doc, 0);
        doc.destroy();
      } catch (err) {
        entry.error = pdfErrorText(err);
      }
      splitFiles.push(entry);
    }
    $('split-result').hidden = true;
    renderSplitList();
  }

  function setSplitProgress(ratio, message) {
    var pct = Math.round(Math.min(1, Math.max(0, ratio)) * 100);
    $('split-progress-bar').style.width = pct + '%';
    $('split-progress-text').textContent = pct + '%';
    if (message != null) $('split-progress-message').textContent = message;
  }

  var yieldToPaint = function () { return new Promise(function (r) { setTimeout(r, 0); }); };

  async function splitToImages() {
    var items = splitFiles.filter(function (f) { return !f.error; });
    if (!items.length) { showToast('אין קבצי PDF תקינים לפיצול', 'error'); return; }

    var mime = getRadioValue('split-format') || 'image/png';
    var ext = SPLIT_EXT[mime] || 'png';
    var quality = mime === 'image/png' ? undefined : Number($('split-quality').value);
    var scale = (Number(getRadioValue('split-dpi')) || 150) / 72;
    var rangeSpec = $('split-range').value;
    var zipName = safeName($('split-zipname').value.replace(/\.zip$/i, ''), 'pages') + '.zip';
    // כמה קבצים — לכל אחד תיקייה משלו ב-ZIP, כדי שהעמודים לא יתערבבו
    var useFolders = items.length > 1;

    var $run = $('split-run');
    $run.disabled = true;
    $('split-result').hidden = true;
    $('split-progress').hidden = false;
    setSplitProgress(0, 'מתחיל…');

    var failures = [];
    var docs = [];
    try {
      // קודם מפרשים את הטווח לכל הקבצים — כדי שההתקדמות תהיה רציפה, ושטווח שגוי ייעצר לפני העבודה
      var totalPages = 0;
      for (var i = 0; i < items.length; i++) {
        try {
          var doc = await openPdfJs(items[i].data);
          var pages = parsePageRange(rangeSpec, doc.numPages);
          docs.push({ item: items[i], doc: doc, pages: pages });
          totalPages += pages.length;
        } catch (err) {
          failures.push(items[i].name + ' — ' + pdfErrorText(err));
        }
      }
      if (!totalPages) throw new Error(failures.join(' · ') || 'אין עמודים לייצוא');

      var zip = new JSZip();
      var canvas = document.createElement('canvas');
      var ctx = canvas.getContext('2d');
      var done = 0;
      var count = 0;

      for (var d = 0; d < docs.length; d++) {
        var plan = docs[d];
        var folder = useFolders ? safeName(plan.item.name.replace(/\.pdf$/i, ''), 'pdf' + (d + 1)) + '/' : '';
        var pad = Math.max(3, String(plan.doc.numPages).length);
        try {
          for (var k = 0; k < plan.pages.length; k++) {
            var pageNum = plan.pages[k];
            setSplitProgress(done / totalPages, (done + 1) + '/' + totalPages + ' · ' + plan.item.name);
            await yieldToPaint();

            var page = await plan.doc.getPage(pageNum);
            var viewport = page.getViewport({ scale: scale });
            canvas.width = Math.max(1, Math.ceil(viewport.width));
            canvas.height = Math.max(1, Math.ceil(viewport.height));
            // רקע לבן: PDF מניח נייר לבן, ו-JPG לא תומך בשקיפות
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            await page.render({ canvasContext: ctx, viewport: viewport }).promise;
            page.cleanup();

            var blob = await new Promise(function (res, rej) {
              canvas.toBlob(function (b) { b ? res(b) : rej(new Error('יצירת התמונה נכשלה')); }, mime, quality);
            });
            zip.file(folder + 'page-' + String(pageNum).padStart(pad, '0') + '.' + ext, blob);
            count++;
            done++;
          }
        } catch (err) {
          failures.push(plan.item.name + ' — ' + pdfErrorText(err));
        }
      }
      if (!count) throw new Error(failures.join(' · ') || 'לא נוצרה אף תמונה');

      setSplitProgress(1, 'אורז את ה-ZIP…');
      var zipBlob = await zip.generateAsync(
        { type: 'blob', compression: 'DEFLATE', compressionOptions: { level: 6 } },
        function (m) { setSplitProgress(1, 'אורז את ה-ZIP… ' + Math.round(m.percent) + '%'); }
      );

      lastSplitBlob = zipBlob;
      lastSplitName = zipName;
      triggerDownload(zipBlob, zipName);

      $('split-result-title').textContent = zipName;
      setBidiText($('split-result-meta'), [count === 1 ? 'תמונה אחת' : count + ' תמונות', formatBytes(zipBlob.size)]);
      $('split-result').hidden = false;
      if (failures.length) showToast('דילגתי על ' + failures.length + ': ' + failures.join(' · '), 'warning');
      else showToast('ה-ZIP הורד — ' + count + ' תמונות', 'success');
    } catch (err) {
      showToast('שגיאה בפיצול: ' + err.message, 'error');
    } finally {
      docs.forEach(function (p) { p.doc.destroy(); });
      $('split-progress').hidden = true;
      $run.disabled = false;
    }
  }

  // ── Mode tabs ──────────────────────────────────────
  function setMode(mode) {
    document.querySelectorAll('.mode-tab').forEach(function (t) {
      var on = t.dataset.mode === mode;
      t.classList.toggle('active', on);
      t.setAttribute('aria-selected', String(on));
    });
    $('mode-merge').hidden = mode !== 'merge';
    $('mode-split').hidden = mode !== 'split';
  }

  // ── Event listeners ────────────────────────────────

  document.querySelectorAll('.mode-tab').forEach(function (t) {
    t.addEventListener('click', function () { setMode(t.dataset.mode); });
  });

  // split mode
  var $splitDrop = $('split-drop');
  var $splitInput = $('split-input');
  $splitDrop.addEventListener('click', function () { $splitInput.click(); });
  $splitDrop.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $splitInput.click(); }
  });
  ['dragenter', 'dragover'].forEach(function (type) {
    $splitDrop.addEventListener(type, function (e) { e.preventDefault(); $splitDrop.classList.add('dragover'); });
  });
  $splitDrop.addEventListener('dragleave', function () { $splitDrop.classList.remove('dragover'); });
  $splitDrop.addEventListener('drop', function (e) {
    e.preventDefault();
    $splitDrop.classList.remove('dragover');
    if (e.dataTransfer.files.length) addSplitFiles(e.dataTransfer.files);
  });
  $splitInput.addEventListener('change', function () {
    if ($splitInput.files.length) { addSplitFiles($splitInput.files); $splitInput.value = ''; }
  });
  $('split-clear').addEventListener('click', function () {
    splitFiles = [];
    $('split-result').hidden = true;
    renderSplitList();
  });
  document.querySelectorAll('input[name="split-format"]').forEach(function (r) {
    r.addEventListener('change', function () {
      $('split-quality-group').hidden = getRadioValue('split-format') === 'image/png';
    });
  });
  $('split-quality').addEventListener('input', function (e) {
    $('split-quality-out').textContent = Math.round(Number(e.target.value) * 100) + '%';
  });
  $('split-run').addEventListener('click', function () { splitToImages(); });
  $('split-download-again').addEventListener('click', function () {
    if (lastSplitBlob) triggerDownload(lastSplitBlob, lastSplitName);
  });

  // merge: מיון, ניקוי, שוליים
  $('btn-sort-name').addEventListener('click', sortFilesByName);
  $('btn-clear-all').addEventListener('click', function () {
    files = [];
    updateTotalPageCount();
    renderFileList();
  });
  // שוליים רלוונטיים רק בגודל דף קבוע — "לפי המקור" הדף הוא בדיוק גודל התמונה
  document.querySelectorAll('input[name="page-size"]').forEach(function (r) {
    r.addEventListener('change', function () {
      var fixed = getRadioValue('page-size') !== 'original';
      $margin.disabled = !fixed;
      $('margin-hint').textContent = fixed ? 'התמונה ממורכזת בתוך השוליים' : 'בגודל דף קבוע (A4 / Letter / Legal)';
    });
  });

  // drop zone
  $dropZone.addEventListener('dragenter', function (e) { e.preventDefault(); $dropZone.classList.add('dragover'); });
  $dropZone.addEventListener('dragover', function (e) { e.preventDefault(); $dropZone.classList.add('dragover'); });
  $dropZone.addEventListener('dragleave', function () { $dropZone.classList.remove('dragover'); });
  $dropZone.addEventListener('drop', function (e) {
    e.preventDefault();
    $dropZone.classList.remove('dragover');
    if (e.dataTransfer.files.length) addFiles(e.dataTransfer.files);
  });

  $dropZone.addEventListener('click', function () { $fileInput.click(); });
  $fileInput.addEventListener('change', function () {
    if ($fileInput.files.length) { addFiles($fileInput.files); $fileInput.value = ''; }
  });

  // step navigation
  $btnToStep2.addEventListener('click', function () {
    if (files.length === 0) { showToast('יש להוסיף קבצים קודם', 'error'); return; }
    goToStep(2);
  });
  $btnToStep1.addEventListener('click', function () { goToStep(1); });
  $btnToStep2Back.addEventListener('click', function () { goToStep(2); });
  $btnMerge.addEventListener('click', function () { mergePdf(); });

  $btnDownloadAgain.addEventListener('click', function () {
    if (lastMergedBlob) triggerDownload(lastMergedBlob, lastMergedName);
  });

  $btnNewMerge.addEventListener('click', function () {
    files = [];
    lastMergedBlob = null;
    lastMergedName = '';
    updateTotalPageCount();
    renderFileList();
    goToStep(1);
  });

  // preview modal
  $previewClose.addEventListener('click', closePreview);
  $previewModal.addEventListener('click', function (e) { if (e.target === $previewModal) closePreview(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && $previewModal.classList.contains('open')) closePreview();
  });

  // init
  goToStep(1);
  renderFileList();

  // התקנה כאפליקציה ועבודה בלי רשת
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('service-worker.js').catch(function () {});

})();
