// The letter editor: the preview follows the text, the button says what it
// will do. Without this script the page still works: "Mettre à jour l'aperçu"
// re-renders it on the server.
(() => {
    'use strict';
    // Touching the date or the time means "at that moment" (edit and letter pages).
    document.addEventListener('focusin', e => {
        if (e.target.name !== 'at_date' && e.target.name !== 'at_time') return;
        const at = e.target.form?.querySelector('input[name="when"][value="at"]');
        if (at && !at.checked) { at.checked = true; at.dispatchEvent(new Event('change', { bubbles: true })); }
    });

    // A form that deletes asks first (subscribers page). The CSP forbids an
    // inline onclick; without this script the button deletes directly.
    document.addEventListener('submit', e => {
        const question = e.target.dataset?.confirm;
        if (question && !window.confirm(question)) e.preventDefault();
    });

    const form = document.getElementById('redaction');
    if (!form) return;

    const subject = form.elements.subject;
    const body = form.elements.body;
    const outSubject = document.getElementById('apercu-objet');
    const outBody = document.getElementById('apercu-corps');
    const save = document.getElementById('enregistrer');
    const refresh = document.getElementById('previsualiser');
    if (refresh) refresh.hidden = true;

    // The policy requires Trusted Types for HTML sinks. The HTML comes from
    // our own Markdown renderer, which escapes any HTML typed in the text.
    const policy = window.trustedTypes?.createPolicy('apercu-lettre', { createHTML: s => s }) ?? { createHTML: s => s };

    subject.addEventListener('input', () => { outSubject.textContent = subject.value.trim() || 'Sans objet'; });

    let timer = null, last = null;
    const render = async () => {
        const text = body.value;
        if (text === last) return;
        last = text;
        if (!text.trim()) {
            outBody.replaceChildren(Object.assign(document.createElement('p'), { className: 'attente', textContent: 'Le texte apparaît ici à mesure qu\'il est écrit.' }));
            return;
        }
        try {
            const res = await fetch(form.dataset.apercu, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ body: text }) });
            if (!res.ok) throw new Error(String(res.status));
            const data = await res.json();
            if (text === body.value) outBody.innerHTML = policy.createHTML(data.html);
        } catch (e) {
            last = null;
            if (refresh) refresh.hidden = false;
        }
    };
    body.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(render, 300); });

    // The submit button names what happens: save, send, schedule.
    const labels = JSON.parse(save.dataset.labels || '{}');
    const label = () => {
        const when = form.querySelector('input[name="when"]:checked')?.value || 'draft';
        save.textContent = labels[when.split(':')[0]] || labels.draft;
        save.classList.toggle('envoi', when !== 'draft');
    };
    form.addEventListener('change', e => { if (e.target.name === 'when') label(); });
    label();
})();
