(function () {
  "use strict";
  var shortcuts = { F2: "product", F3: "purchase", F4: "sale" };
  document.addEventListener("keydown", function (event) {
    var targetId = shortcuts[event.key];
    if (!targetId || event.altKey || event.ctrlKey || event.metaKey) return;
    event.preventDefault();
    var section = document.getElementById(targetId);
    if (!section) return;
    section.scrollIntoView({ behavior: "smooth", block: "start" });
    var field = section.querySelector('select, input:not([type="hidden"]), button');
    if (field) window.setTimeout(function () { field.focus(); }, 180);
  });
})();