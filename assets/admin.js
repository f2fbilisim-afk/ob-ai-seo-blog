(function () {
	var toggle = document.getElementById('ob-ai-schedule-enabled');
	var fields = document.querySelector('.ob-ai-schedule-fields');
	if (!toggle || !fields) {
		return;
	}
	function sync() {
		fields.hidden = !toggle.checked;
	}
	toggle.addEventListener('change', sync);
	sync();
})();
