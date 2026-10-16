# Sharing cards

One 1200×630 image per relevé (`public/cards/releve-N.png`), what LinkedIn and
others show under a link. Drawn from `releve-N.html` with the site's own fonts:

    podman run --rm -v "$PWD:/s:Z" docker.io/zenika/alpine-chrome:124 --no-sandbox --headless \
      --hide-scrollbars --timeout=5000 --window-size=1200,630 \
      --screenshot=/s/public/cards/releve-1.png file:///s/cards/releve-1.html

The figures on a card are copied by hand from the relevé: check them against
the page before publishing.
