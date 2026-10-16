/* jshint ignore:start */
var ftc_strings = (typeof ftc_strings !== 'undefined') ? ftc_strings : {
	fetching: 'Fetching content…',
	success:  'Content updated.',
	error:    'Failed to fetch content.',
};
/* jshint ignore:end */

(function () {
	function init() {
		var global = document.getElementById('global');
		if (!global) { return; }
		global.addEventListener('click', function (e) {
			for (var target = e.target; target && target !== this; target = target.parentNode) {
				if (target.matches('.ftc-refetch a.btn')) {
					e.preventDefault();
					e.stopPropagation();
					if (target.href) {
						refetchButtonClick(target);
					}
					break;
				}
			}
		}, false);
	}

	if (document.readyState !== 'loading') {
		init();
	} else {
		document.addEventListener('DOMContentLoaded', init, false);
	}

	function text(container, key) {
		try {
			var strings = JSON.parse(container.getAttribute('data-strings') || '{}');
			if (strings[key]) { return strings[key]; }
		} catch (e) {
			// fall back to the built-in English strings
		}
		return ftc_strings[key];
	}

	function setState(container, state, msg) {
		var status = container.querySelector('.ftc-status');
		container.classList.remove('ftc-loading', 'ftc-success', 'ftc-error');
		status.classList.remove('hidden');
		switch (state) {
			case 'loading':
				container.classList.add('ftc-loading');
				status.textContent = text(container, 'fetching');
				break;
			case 'success':
				container.classList.add('ftc-success');
				status.textContent = text(container, 'success');
				break;
			case 'error':
				container.classList.add('ftc-error');
				status.textContent = msg || text(container, 'error');
				break;
			case 'idle':
				status.classList.add('hidden');
				status.textContent = '';
				break;
		}
	}

	function refetchButtonClick(button) {
		var container = button.parentNode;
		if (container.classList.contains('ftc-loading')) {
			return;
		}

		setState(container, 'loading');

		var url = button.href;
		var request = new XMLHttpRequest();
		request.open('POST', url, true);
		request.responseType = 'json';

		request.onload = function (e) {
			if (this.status !== 200) {
				return request.onerror(e);
			}
			var resp = this.response;
			if (!resp || resp.status !== 200) {
				setState(container, 'error', resp && resp.error ? resp.error : null);
				return;
			}

			setState(container, 'success');

			// Replace entry content nodes that follow the button bar
			var parent = container.parentNode;
			while (container.nextSibling) {
				parent.removeChild(container.nextSibling);
			}
			var div = document.createElement('div');
			div.innerHTML = resp.content;
			while (div.firstChild) {
				parent.appendChild(div.firstChild);
			}
		};

		request.onerror = function () {
			setState(container, 'error', null);
		};

		var csrf = (typeof window.context !== 'undefined') ? context.csrf : '';
		request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
		request.send('_csrf=' + encodeURIComponent(csrf));
	}
}());

// Configure page: feed picker and filter, option summaries and the
// extraction test. FreshRSS may load the page into its slider by AJAX, where
// inline scripts do not run, so everything is delegated from the document.
(function () {
	function root(el) {
		return el.closest ? el.closest('.ftc-feeds') : null;
	}

	function strings(section) {
		if (!section._ftcStrings) {
			try {
				section._ftcStrings = JSON.parse(section.getAttribute('data-strings') || '{}');
			} catch (e) {
				section._ftcStrings = {};
			}
		}
		return section._ftcStrings;
	}

	function format(template) {
		var args = Array.prototype.slice.call(arguments, 1);
		return String(template || '').replace(/%[sd]/g, function () {
			return args.length ? String(args.shift()) : '';
		});
	}

	function optionValue(item, name) {
		var field = item.querySelector('[data-ftc-opt="' + name + '"]');
		if (!field) { return ''; }
		return field.type === 'checkbox' ? field.checked : field.value.trim();
	}

	// Mirrors $summarize in configure.phtml.
	function updateSummary(section, item) {
		var s = strings(section);
		var parts = [];
		var selector = optionValue(item, 'selector');
		var wait = parseInt(optionValue(item, 'wait'), 10);
		var waitUntil = optionValue(item, 'wait_until');
		var timeout = parseInt(optionValue(item, 'timeout'), 10);
		if (selector) { parts.push(format(s.selector, selector)); }
		if (wait > 0) { parts.push(format(s.wait, wait)); }
		if (s['wait_until_' + waitUntil]) { parts.push(s['wait_until_' + waitUntil]); }
		if (timeout > 0) { parts.push(format(s.timeout, timeout)); }
		if (optionValue(item, 'stealth')) { parts.push(s.stealth); }
		item.querySelector('.ftc-feed-summary').textContent = parts.length ? parts.join(' · ') : s['default'];
	}

	function updateCounts(section) {
		var enabledList = section.querySelector('.ftc-enabled-list');
		section.querySelector('.ftc-enabled-count').textContent =
			enabledList.querySelectorAll('.ftc-feed-enabled:checked').length;
		section.querySelector('.ftc-add-count').textContent =
			section.querySelectorAll('.ftc-add-list .ftc-feed').length;
		section.querySelector('.ftc-no-enabled').hidden = enabledList.querySelector('.ftc-feed') !== null;
		section.querySelectorAll('.ftc-add-list .ftc-category').forEach(function (category) {
			category.hidden = category.querySelector('.ftc-feed:not([hidden])') === null;
		});
		section.querySelectorAll('.ftc-feed').forEach(function (item) {
			item.classList.toggle('ftc-enabled', item.querySelector('.ftc-feed-enabled').checked);
		});
	}

	// A feed ticked in "Add feeds" moves to the enabled feeds so that its
	// options can be set right away. Unticking an enabled feed leaves it in
	// place until the form is saved, so a mis-click is easy to undo.
	function toggleFeed(section, item, checkbox) {
		if (checkbox.checked && item.closest('.ftc-add-list')) {
			item.hidden = false;
			section.querySelector('.ftc-enabled-list').appendChild(item);
		}
		updateCounts(section);
		markDirty(section);
	}

	function markDirty(section) {
		var note = section.querySelector('.ftc-dirty-note');
		if (note) { note.hidden = false; }
		section.ftcDirty = true;
	}

	function applyFilter(section) {
		var query = section.querySelector('.ftc-feed-filter').value.trim().toLowerCase();
		var anyShown = false;
		section.querySelectorAll('.ftc-add-list .ftc-feed').forEach(function (item) {
			var name = item.querySelector('.ftc-feed-name').textContent.toLowerCase();
			item.hidden = query !== '' && name.indexOf(query) === -1;
			if (!item.hidden) { anyShown = true; }
		});
		section.querySelector('.ftc-no-match').hidden = anyShown;
		updateCounts(section);
	}

	function runTest(section, item, button) {
		var s = strings(section);
		var result = item.querySelector('.ftc-test-result');
		var params = {
			_csrf: section.getAttribute('data-csrf'),
			feed: item.getAttribute('data-feed-id'),
			url: item.querySelector('.ftc-test-url').value.trim(),
			selector: optionValue(item, 'selector'),
			wait: optionValue(item, 'wait'),
			wait_until: optionValue(item, 'wait_until'),
			timeout: optionValue(item, 'timeout'),
			stealth: optionValue(item, 'stealth') ? '1' : '0',
		};
		var body = Object.keys(params).map(function (key) {
			return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
		}).join('&');

		button.disabled = true;
		result.hidden = false;
		result.className = 'ftc-test-result ftc-loading';
		result.textContent = s.test_running;

		function fail(message) {
			result.className = 'ftc-test-result ftc-error';
			result.textContent = format(s.test_failed, message || '');
		}

		var request = new XMLHttpRequest();
		request.open('POST', section.getAttribute('data-preview-url'), true);
		request.responseType = 'json';
		request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
		request.onload = function () {
			button.disabled = false;
			var resp = this.response;
			if (!resp || resp.status !== 200) {
				fail(resp && resp.error ? resp.error : String(this.status));
				return;
			}
			result.className = 'ftc-test-result ftc-success';
			result.textContent = '';
			var info = document.createElement('p');
			info.className = 'ftc-test-info';
			info.textContent = format(s.test_ok, resp.characters.toLocaleString(),
				(resp.elapsed_ms / 1000).toFixed(1), resp.url);
			// The extracted page is shown in a sandboxed frame: no scripts, no
			// access to FreshRSS.
			var frame = document.createElement('iframe');
			frame.className = 'ftc-test-preview';
			frame.setAttribute('sandbox', '');
			frame.setAttribute('referrerpolicy', 'no-referrer');
			frame.srcdoc = '<!doctype html><meta charset="utf-8"><base target="_blank">' +
				'<style>body{font:15px/1.5 system-ui,sans-serif;margin:1em;color:#222}img{max-width:100%;height:auto}</style>' +
				resp.content;
			result.appendChild(info);
			result.appendChild(frame);
		};
		request.onerror = function () {
			button.disabled = false;
			fail('');
		};
		request.send(body);
	}

	document.addEventListener('input', function (e) {
		var section = root(e.target);
		if (!section) { return; }
		if (e.target.classList.contains('ftc-feed-filter')) {
			applyFilter(section);
			return;
		}
		var item = e.target.closest('.ftc-feed');
		if (item && e.target.hasAttribute('data-ftc-opt')) {
			updateSummary(section, item);
			markDirty(section);
		}
	}, false);

	document.addEventListener('change', function (e) {
		var section = root(e.target);
		if (!section) { return; }
		var item = e.target.closest('.ftc-feed');
		if (!item) { return; }
		if (e.target.classList.contains('ftc-feed-enabled')) {
			toggleFeed(section, item, e.target);
		} else if (e.target.hasAttribute('data-ftc-opt')) {
			updateSummary(section, item);
			markDirty(section);
		}
	}, false);

	document.addEventListener('click', function (e) {
		var button = e.target.closest ? e.target.closest('.ftc-test-btn') : null;
		var section = button ? root(button) : null;
		if (!section) { return; }
		e.preventDefault();
		runTest(section, button.closest('.ftc-feed'), button);
	}, false);

	document.addEventListener('submit', function (e) {
		var section = root(e.target);
		if (section) { section.ftcDirty = false; }
	}, false);

	window.addEventListener('beforeunload', function (e) {
		var section = document.querySelector('.ftc-feeds');
		if (section && section.ftcDirty) {
			e.preventDefault();
			e.returnValue = '';
		}
	});
}());
