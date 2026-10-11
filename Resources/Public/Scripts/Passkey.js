/*
 * Passkeys in the browser, and the confirmations before a destructive button.
 *
 * The browser speaks ArrayBuffers, JSON speaks text: the translation both ways lives here, so
 * the pages only mark a button with data-calmfox-passkey="login" or "register" and say where
 * to ask for options and where to send the answer. Loaded once per page, however many times
 * the tag appears.
 */
(() => {
    if (window.CalmfoxPasskey) {
        return;
    }

    const decode = (value) => {
        const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
        const binary = atob(base64.padEnd(Math.ceil(base64.length / 4) * 4, '='));
        return Uint8Array.from(binary, (c) => c.charCodeAt(0)).buffer;
    };
    const encode = (buffer) => btoa(String.fromCharCode(...new Uint8Array(buffer)))
        .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    const descriptors = (list) => (list || []).map((d) => ({ ...d, type: 'public-key', id: decode(d.id) }));

    const post = async (url, csrf, body = {}) => {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Flow-Csrftoken': csrf },
            body: JSON.stringify(body),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const error = new Error(data.error || response.statusText);
            error.redirect = data.redirect || data.location;
            throw error;
        }
        return data;
    };

    // closing the system dialog or timing out is not a failure worth an error message
    const cancelled = (error) => error instanceof DOMException && ['NotAllowedError', 'AbortError'].includes(error.name);

    const supported = () => typeof window.PublicKeyCredential === 'function' && typeof navigator.credentials?.create === 'function';

    const create = async (options) => {
        const credential = await navigator.credentials.create({ publicKey: {
            ...options,
            challenge: decode(options.challenge),
            user: { ...options.user, id: decode(options.user.id) },
            excludeCredentials: descriptors(options.excludeCredentials),
        } });
        return {
            id: credential.id,
            rawId: encode(credential.rawId),
            type: credential.type,
            response: {
                clientDataJSON: encode(credential.response.clientDataJSON),
                attestationObject: encode(credential.response.attestationObject),
            },
        };
    };

    const get = async (options) => {
        const credential = await navigator.credentials.get({ publicKey: {
            ...options,
            challenge: decode(options.challenge),
            allowCredentials: descriptors(options.allowCredentials),
        } });
        const response = {
            clientDataJSON: encode(credential.response.clientDataJSON),
            authenticatorData: encode(credential.response.authenticatorData),
            signature: encode(credential.response.signature),
        };
        if (credential.response.userHandle) {
            response.userHandle = encode(credential.response.userHandle);
        }
        return { id: credential.id, rawId: encode(credential.rawId), type: credential.type, response };
    };

    // a name the person will recognise in the list later; they can have several devices
    const deviceName = () => {
        const ua = navigator.userAgent;
        for (const [needle, name] of [['iPhone', 'iPhone'], ['iPad', 'iPad'], ['Android', 'Android'], ['Mac', 'Mac'], ['Windows', 'Windows'], ['Linux', 'Linux']]) {
            if (ua.includes(needle)) return name;
        }
        return '';
    };

    const start = async (button) => {
        const status = document.getElementById(button.dataset.status || '');
        const say = (text) => { if (status) status.textContent = text || ''; };

        if (!supported()) {
            say(button.dataset.unsupported);
            return;
        }

        button.disabled = true;
        say(button.dataset.waiting);
        try {
            const { options } = await post(button.dataset.optionsUri, button.dataset.csrf);
            const result = button.dataset.calmfoxPasskey === 'login'
                ? await post(button.dataset.uri, button.dataset.csrf, { credential: await get(options) })
                : await post(button.dataset.uri, button.dataset.csrf, { credential: await create(options), name: deviceName() });
            if (result.redirect) {
                window.location.assign(result.redirect);
            } else {
                window.location.reload();
            }
        } catch (error) {
            say(cancelled(error) ? '' : (error.message || button.dataset.failed));
            if (error.redirect) {
                window.setTimeout(() => window.location.assign(error.redirect), 1500);
                return;
            }
            button.disabled = false;
        }
    };

    window.CalmfoxPasskey = { supported, cancelled, post, create, get, deviceName };

    const wire = () => {
        document.querySelectorAll('[data-calmfox-passkey]').forEach((button) => {
            button.addEventListener('click', () => start(button));
        });

        // a form that removes something asks first, in words the server translated
        document.querySelectorAll('form[data-confirm]').forEach((form) => {
            form.addEventListener('submit', (event) => {
                if (!window.confirm(form.dataset.confirm)) {
                    event.preventDefault();
                }
            });
        });

        // On a user's page in Neos' user management our section is rendered after the page's
        // content, and so after Neos' sticky footer with Back and Delete. The footer belongs
        // last; without this script the section still works, just below it.
        document.querySelectorAll('.cf2fa-user-section').forEach((section) => {
            const footer = section.parentElement?.querySelector(':scope > .neos-footer, :scope > * > .neos-footer');
            footer?.parentElement.insertBefore(section, footer);
        });

        // without a passkey to press, the code field is the only thing to do on the page
        if (!document.querySelector('[data-calmfox-passkey="login"]')) {
            document.getElementById('cf2fa-code')?.focus();
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wire);
    } else {
        wire();
    }
})();
