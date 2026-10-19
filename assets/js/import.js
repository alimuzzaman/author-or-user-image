/* Progressive enhancement for explicit, sequential administrator import POSTs. */
(function () {
	'use strict';
	var root = document.querySelector('[data-zmai-import]');
	if (!root || !window.fetch || !window.FormData || !window.URLSearchParams) { return; }
	var working = false;
	var stopRequested = false;
	var stopButton;
	var message = root.querySelector('[data-zmai-message]');
	function show(job) {
		root.querySelector('[data-zmai-state]').textContent = job.state;
		Object.keys(job.counts).forEach(function (key) {
			var target = root.querySelector('[data-zmai-count="' + key + '"]');
			if (target) { target.textContent = String(job.counts[key]); }
		});
	}
	async function post(url, fields, binding) {
		fields.set('format', 'json');
		var response = await fetch(url, {method: 'POST', credentials: 'same-origin', body: fields});
		var result = await response.json();
		if (!response.ok || !result.success || !result.data || !result.data.job) {
			throw new Error('import-stopped');
		}
		var job = result.data.job;
		if (job.id !== binding.id || String(job.site_id) !== binding.site ||
			typeof job.source !== 'string' || !job.source || (binding.source !== null && job.source !== binding.source)) {
			throw new Error('import-conflict');
		}
		binding.source = job.source;
		show(result.data.job);
		return result.data;
	}
	root.addEventListener('submit', async function (event) {
		var form = event.target;
		if (!form.matches('form[data-zmai-operation]')) { return; }
		event.preventDefault();
		if (working) { return; }
		working = true;
		stopRequested = false;
		var fields = new FormData(form);
		var starting = fields.get('action') === 'zmai_import_start';
		var binding = {
			id: fields.get(starting ? 'run_token' : 'job_id'),
			site: fields.get('site_id'),
			source: starting ? fields.get('source') : null
		};
		var url = form.getAttribute('action');
		// Disable native submit inputs, buttons and the source selection during the run.
		root.querySelectorAll('button, input[type="submit"], select').forEach(function (control) { control.disabled = true; });
		stopButton = document.createElement('button');
		stopButton.type = 'button';
		stopButton.className = 'button';
		// Reuse the translated native Stop label when present, otherwise its page text.
		var nativeStop = root.querySelector('[data-zmai-operation="pause"] input[type="submit"], [data-zmai-operation="pause"] button');
		stopButton.textContent = nativeStop ? (nativeStop.value || nativeStop.textContent) : root.getAttribute('data-stop-label');
		if (!stopButton.textContent) { stopButton.textContent = 'Stop'; }
		stopButton.addEventListener('click', function () { stopRequested = true; stopButton.disabled = true; });
		root.appendChild(stopButton);
		try {
			var data = await post(url, fields, binding);
			while (data.job.state === 'running') {
				fields = new FormData();
				var operation = stopRequested ? 'pause' : 'batch';
				fields.set('action', 'zmai_import_' + operation);
				fields.set('site_id', String(data.job.site_id));
				fields.set('job_id', data.job.id);
				fields.set('revision', String(data.job.revision));
				fields.set('_wpnonce', data.nonces[operation]);
				data = await post(url, fields, binding);
			}
			// Reload the server-rendered native controls at the persisted terminal state.
			window.location.reload();
		} catch (error) {
			// An ambiguous response must never trigger an automatic POST retry.
			message.textContent = root.getAttribute('data-error-label');
			stopButton.remove();
			// Keep mutation controls disabled until a fresh GET supplies current state.
		}
	});
}());
