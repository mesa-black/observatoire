// The waiting page: a countdown and a wall of plaques with nothing on them
// but noise. Every node through createElement and textContent: the CSP
// requires Trusted Types, and nothing here needs HTML.
(() => {
    'use strict';
    const box = document.getElementById('compte');
    const at = Date.parse(box.dataset.at);
    const n = document.getElementById('compte-n');
    const what = document.getElementById('compte-quoi');
    const two = v => String(v).padStart(2, '0');
    const tick = () => {
        const left = Math.max(0, at - Date.now());
        if (!left) { location.reload(); return; }
        const s = Math.floor(left / 1000), d = Math.floor(s / 86400);
        n.textContent = d ? `J-${d}` : `${two(Math.floor(s / 3600))}:${two(Math.floor(s / 60) % 60)}`;
        what.textContent = d ? `${two(Math.floor(s / 3600) % 24)}:${two(Math.floor(s / 60) % 60)}:${two(s % 60)}` : `et ${two(s % 60)} s`;
    };
    tick();
    box.hidden = false;
    setInterval(tick, 1000);

    // Noise that keeps changing: what a recording of today's traffic looks like.
    const wall = document.getElementById('mur');
    const hex = '0123456789abcdef';
    const noise = len => Array.from({ length: len }, (_, i) => hex[(Math.random() * 16) | 0] + (i % 4 === 3 ? ' ' : '')).join('').trim();
    const cells = [];
    for (let i = 0; i < 24; i++) {
        const flux = document.createElement('span');
        flux.className = 'flux';
        flux.textContent = noise(16);
        const etat = document.createElement('span');
        etat.className = 'etat';
        etat.textContent = '? · lundi';
        const li = document.createElement('li');
        li.className = 'plaque';
        li.append(flux, etat);
        wall.append(li);
        cells.push(flux);
    }
    if (!matchMedia('(prefers-reduced-motion: reduce)').matches) {
        setInterval(() => { cells[(Math.random() * cells.length) | 0].textContent = noise(16); }, 140);
    }
})();
