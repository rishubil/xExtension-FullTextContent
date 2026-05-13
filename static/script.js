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

	function setState(container, state, msg) {
		var status = container.querySelector('.ftc-status');
		container.classList.remove('ftc-loading', 'ftc-success', 'ftc-error');
		status.classList.remove('hidden');
		switch (state) {
			case 'loading':
				container.classList.add('ftc-loading');
				status.textContent = ftc_strings.fetching;
				break;
			case 'success':
				container.classList.add('ftc-success');
				status.textContent = ftc_strings.success;
				break;
			case 'error':
				container.classList.add('ftc-error');
				status.textContent = msg || ftc_strings.error;
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
