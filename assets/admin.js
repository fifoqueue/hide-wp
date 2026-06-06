(() => {
	'use strict';

	const tabs = Array.from(document.querySelectorAll('[data-hide-wp-tab]'));
	const panels = Array.from(document.querySelectorAll('[data-hide-wp-panel]'));
	const tabStorageKey = 'hideWpSurfaceActiveTab';

	const storedTab = () => {
		try {
			return window.sessionStorage.getItem(tabStorageKey);
		} catch {
			return null;
		}
	};

	const rememberTab = (name) => {
		try {
			window.sessionStorage.setItem(tabStorageKey, name);
		} catch {
			// Storage can be unavailable in hardened browser configurations.
		}
	};

	const tabFromHash = () => {
		const match = window.location.hash.match(/^#hide-wp-tab-([a-z]+)$/);

		return match ? match[1] : null;
	};

	const activateTab = (name, focus = false, updateUrl = false) => {
		const activeTab = tabs.find((tab) => tab.dataset.hideWpTab === name);
		const activePanel = panels.find((panel) => panel.dataset.hideWpPanel === name);

		if (!activeTab || !activePanel) {
			return;
		}

		tabs.forEach((tab) => {
			const active = tab === activeTab;
			tab.classList.toggle('nav-tab-active', active);
			tab.setAttribute('aria-selected', active ? 'true' : 'false');
			tab.tabIndex = active ? 0 : -1;
		});

		panels.forEach((panel) => {
			panel.hidden = panel !== activePanel;
		});

		rememberTab(name);

		if (updateUrl) {
			const url = new URL(window.location.href);
			url.hash = activeTab.id;
			window.history.replaceState(null, '', url);
		}

		if (focus) {
			activeTab.focus();
		}
	};

	if (tabs.length > 0 && tabs.length === panels.length) {
		const validTab = (name) => tabs.some((tab) => tab.dataset.hideWpTab === name);

		tabs.forEach((tab, index) => {
			tab.addEventListener('click', () => {
				activateTab(tab.dataset.hideWpTab, false, true);
			});

			tab.addEventListener('keydown', (event) => {
				let targetIndex;

				switch (event.key) {
					case 'ArrowLeft':
					case 'ArrowUp':
						targetIndex = (index - 1 + tabs.length) % tabs.length;
						break;
					case 'ArrowRight':
					case 'ArrowDown':
						targetIndex = (index + 1) % tabs.length;
						break;
					case 'Home':
						targetIndex = 0;
						break;
					case 'End':
						targetIndex = tabs.length - 1;
						break;
					default:
						return;
				}

				event.preventDefault();
				activateTab(tabs[targetIndex].dataset.hideWpTab, true, true);
			});
		});

		window.addEventListener('hashchange', () => {
			const requestedTab = tabFromHash();
			activateTab(validTab(requestedTab) ? requestedTab : tabs[0].dataset.hideWpTab);
		});

		const requestedTab = tabFromHash() || storedTab();
		activateTab(validTab(requestedTab) ? requestedTab : tabs[0].dataset.hideWpTab);
	}

	const result = document.getElementById('hide-wp-result');
	const verify = document.getElementById('hide-wp-verify');
	const disable = document.getElementById('hide-wp-disable');
	const loginResult = document.getElementById('hide-wp-login-result');
	const loginVerify = document.getElementById('hide-wp-verify-login');

	if (!result || !verify || !disable || typeof hideWpAdmin === 'undefined') {
		return;
	}

	const run = async (action, button, output) => {
		output.textContent = hideWpAdmin.working;
		output.className = '';
		button.disabled = true;

		const body = new URLSearchParams({
			action,
			nonce: hideWpAdmin.nonce,
		});

		try {
			const response = await fetch(hideWpAdmin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
				body,
			});
			const payload = await response.json();
			output.textContent = payload?.data?.message || hideWpAdmin.failed;
			output.className = payload.success ? 'hide-wp-ok' : 'hide-wp-error';

			if (payload.success) {
				const redirect = new URL(payload?.data?.redirect || window.location.href, window.location.href);
				redirect.hash = window.location.hash;
				const destination = redirect.origin === window.location.origin
					? redirect.href
					: window.location.href;

				window.setTimeout(() => window.location.assign(destination), 900);
			}
		} catch {
			output.textContent = hideWpAdmin.failed;
			output.className = 'hide-wp-error';
		} finally {
			button.disabled = false;
		}
	};

	if (loginResult && loginVerify) {
		loginVerify.addEventListener('click', () => run(hideWpAdmin.loginAction, loginVerify, loginResult));
	}

	verify.addEventListener('click', () => run(hideWpAdmin.verifyAction, verify, result));
	disable.addEventListener('click', () => run(hideWpAdmin.disableAction, disable, result));
})();
