// Passkeys for the admin: sign in with one (login page), add one (dashboard).
// The server speaks WebAuthn's JSON (base64url), the browser API wants buffers.
// No HTML is ever built from strings: the CSP requires Trusted Types.
(() => {
    'use strict';
    const b64 = s => { const t = s.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - s.length % 4) % 4); return Uint8Array.from(atob(t), c => c.charCodeAt(0)).buffer; };
    const u64 = buf => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    const creation = o => PublicKeyCredential.parseCreationOptionsFromJSON ? PublicKeyCredential.parseCreationOptionsFromJSON(o)
        : { ...o, challenge: b64(o.challenge), user: { ...o.user, id: b64(o.user.id) }, excludeCredentials: (o.excludeCredentials || []).map(c => ({ ...c, id: b64(c.id) })) };
    const request = o => PublicKeyCredential.parseRequestOptionsFromJSON ? PublicKeyCredential.parseRequestOptionsFromJSON(o)
        : { ...o, challenge: b64(o.challenge), allowCredentials: (o.allowCredentials || []).map(c => ({ ...c, id: b64(c.id) })) };
    const toJSON = c => {
        if (typeof c.toJSON === 'function') return c.toJSON();
        const r = c.response, j = { id: c.id, rawId: u64(c.rawId), type: c.type, clientExtensionResults: c.getClientExtensionResults?.() || {}, response: { clientDataJSON: u64(r.clientDataJSON) } };
        if (r.attestationObject) { j.response.attestationObject = u64(r.attestationObject); if (r.getTransports) j.response.transports = r.getTransports(); }
        if (r.authenticatorData) { j.response.authenticatorData = u64(r.authenticatorData); j.response.signature = u64(r.signature); j.response.userHandle = r.userHandle ? u64(r.userHandle) : null; }
        return j;
    };
    const post = async (url, body) => {
        const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) });
        let data = null; try { data = await res.json(); } catch (e) { /* none */ }
        return { ok: res.ok, data };
    };
    const supported = typeof window.PublicKeyCredential !== 'undefined';

    const say = (el, text) => { if (el) { el.textContent = text; el.hidden = !text; } };

    const login = document.getElementById('cle-connexion');
    if (login) {
        const out = document.getElementById('cle-message');
        if (!supported) { login.hidden = true; return; }
        login.hidden = false;
        login.addEventListener('click', async () => {
            say(out, '');
            try {
                const opts = await post(login.dataset.options);
                const cred = await navigator.credentials.get({ publicKey: request(opts.data) });
                const res = await post(login.dataset.check, toJSON(cred));
                if (res.ok && res.data?.redirect) { location.assign(res.data.redirect); return; }
                say(out, res.data?.error || 'Clé d\'accès refusée.');
            } catch (e) { say(out, e.name === 'NotAllowedError' ? 'Annulé.' : 'Clé d\'accès indisponible sur cet appareil.'); }
        });
    }

    const add = document.getElementById('cle-ajout');
    if (add) {
        const out = document.getElementById('cle-ajout-message');
        if (!supported) { add.disabled = true; say(out, 'Ce navigateur ne gère pas les clés d\'accès.'); return; }
        add.addEventListener('click', async () => {
            say(out, '');
            try {
                const opts = await post(add.dataset.options);
                const cred = await navigator.credentials.create({ publicKey: creation(opts.data) });
                const name = document.getElementById('cle-nom')?.value || '';
                const res = await post(add.dataset.register, { credential: toJSON(cred), name });
                if (res.ok && res.data?.redirect) { location.assign(res.data.redirect); return; }
                say(out, res.data?.error || 'La clé n\'a pas pu être ajoutée.');
            } catch (e) { say(out, e.name === 'NotAllowedError' ? 'Annulé.' : e.name === 'InvalidStateError' ? 'Cet appareil a déjà une clé pour ce site.' : 'La clé n\'a pas pu être créée.'); }
        });
    }
})();
