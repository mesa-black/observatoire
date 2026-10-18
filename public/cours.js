// The course's live test (chapters 1 and 8): which key exchange this browser
// just negotiated, asked of handshake.mesa.black (devops/handshake). The
// sentences are built node by node: the page's policy forbids HTML sinks.
// Without an answer, the paragraphs stay hidden and the text reads as is.
(() => {
    'use strict';
    const spots = document.querySelectorAll('[data-handshake]');
    if (!spots.length) return;
    fetch('https://handshake.mesa.black/', { cache: 'no-store', credentials: 'omit' })
        .then(r => (r.ok ? r.json() : null))
        .then(t => {
            if (!t || !t.group) return;
            const tls12 = t.version === 'TLS 1.2';
            for (const el of spots) {
                const b = document.createElement('b');
                b.textContent = 'À l’instant, chez vous. ';
                const group = document.createElement('code');
                group.textContent = t.group;
                const verdict = t.hybrid
                    ? ' : l’hybride post-quantique. Votre connexion est déjà scellée.'
                    : (tls12 ? ', en TLS 1.2, qui ne peut pas porter l’hybride. ' : ' : l’échange classique. ')
                        + 'Une version récente de Chrome, Edge, Firefox ou Safari parlerait l’hybride ; derrière le proxy d’une entreprise qui inspecte le trafic, c’est lui qui a répondu.';
                el.replaceChildren(b, 'Votre navigateur vient de faire une poignée de main avec nous. Elle a utilisé ', group, verdict);
                el.classList.add(t.hybrid ? 'hybride' : 'classique');
                el.hidden = false;
            }
        })
        .catch(() => {});
})();
