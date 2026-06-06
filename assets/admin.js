(() => {
	'use strict';

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
