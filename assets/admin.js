(function () {
  var form = document.getElementById("ob-ai-generate-form");
  var tbody = document.getElementById("ob-ai-kw-rows");
  var btnAdd = document.getElementById("ob-ai-add-row");
  var helpBtn = document.getElementById("ob-ai-help-btn");
  var helpDialog = document.getElementById("ob-ai-help-dialog");
  var helpClose = document.getElementById("ob-ai-help-close");
  var modeRadios = document.querySelectorAll('input[name="publish_mode"]');
  var scheduleBatch = document.querySelector(".ob-ai-schedule-batch");

  function syncScheduleBatch() {
    if (!scheduleBatch) return;
    var mode = document.querySelector('input[name="publish_mode"]:checked');
    scheduleBatch.hidden = !mode || mode.value !== "scheduled";
  }

  modeRadios.forEach(function (r) {
    r.addEventListener("change", syncScheduleBatch);
  });
  syncScheduleBatch();

  function addRow(keyword, dateVal, timeVal) {
    if (!tbody) return;
    var tr = document.createElement("tr");
    tr.className = "ob-ai-kw-row";
    tr.innerHTML =
      '<td class="ob-ai-col-keyword"><input type="text" class="ob-ai-kw-input" placeholder="Anahtar kelime" value="' +
      (keyword || "").replace(/"/g, "&quot;") +
      '" /></td>' +
      '<td><input type="date" class="ob-ai-date-input" value="' +
      (dateVal || "") +
      '" /></td>' +
      '<td><input type="time" class="ob-ai-time-input" value="' +
      (timeVal || "09:00") +
      '" /></td>' +
      '<td><button type="button" class="ob-ai-btn-icon ob-ai-remove-row" title="Sil" aria-label="Sil">&times;</button></td>';
    tbody.appendChild(tr);
    tr.querySelector(".ob-ai-remove-row").addEventListener("click", function () {
      if (tbody.querySelectorAll(".ob-ai-kw-row").length > 1) {
        tr.remove();
      } else {
        tr.querySelector(".ob-ai-kw-input").value = "";
      }
    });
  }

  if (btnAdd) {
    btnAdd.addEventListener("click", function () {
      addRow("", "", "09:00");
    });
  }

  if (tbody && !tbody.querySelector(".ob-ai-kw-row")) {
    addRow("", "", "09:00");
    addRow("", "", "09:00");
  }

  tbody &&
    tbody.querySelectorAll(".ob-ai-remove-row").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var row = btn.closest("tr");
        if (tbody.querySelectorAll(".ob-ai-kw-row").length > 1) {
          row.remove();
        }
      });
    });

  if (form) {
    form.addEventListener("submit", function () {
      var container = document.getElementById("ob-ai-rows-hidden");
      if (!container) return;
      container.innerHTML = "";
      var idx = 0;
      tbody.querySelectorAll(".ob-ai-kw-row").forEach(function (tr) {
        var kw = tr.querySelector(".ob-ai-kw-input");
        var kwVal = kw && kw.value ? kw.value.trim() : "";
        if (!kwVal) return;
        var d = tr.querySelector(".ob-ai-date-input");
        var t = tr.querySelector(".ob-ai-time-input");
        var fields = [
          { name: "ob_ai_rows[" + idx + "][keyword]", value: kwVal },
          { name: "ob_ai_rows[" + idx + "][date]", value: d ? d.value : "" },
          { name: "ob_ai_rows[" + idx + "][time]", value: t ? t.value : "" },
        ];
        fields.forEach(function (f) {
          var inp = document.createElement("input");
          inp.type = "hidden";
          inp.name = f.name;
          inp.value = f.value;
          container.appendChild(inp);
        });
        idx++;
      });
    });
  }

  if (helpBtn && helpDialog) {
    helpBtn.addEventListener("click", function () {
      if (helpDialog.showModal) helpDialog.showModal();
    });
  }
  if (helpClose && helpDialog) {
    helpClose.addEventListener("click", function () {
      helpDialog.close();
    });
  }
})();
