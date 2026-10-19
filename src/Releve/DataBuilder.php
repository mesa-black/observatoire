<?php

namespace App\Releve;

/**
 * The page's data for one relevé: what observatoire-pq/build.sh makes with jq,
 * made here in PHP from the same files, to the same bytes once encoded
 * (tests/Releve/DataBuilderTest.php holds it to that).
 *
 * Objects are kept as stdClass rather than arrays on purpose: an empty `{}`
 * must stay `{}` (meta), and keys must keep jq's order — a key already present
 * keeps its place when replaced, a new one goes last — which is exactly what
 * assigning a property does.
 */
final class DataBuilder
{
    /** @var array<int, string> encoded data, per relevé number: built once per process */
    private array $built = [];

    public function json(Releve $releve): string
    {
        // JSON_HEX_TAG: the data sits inside a <script> element, and "</script>"
        // in a probed header must not be able to close it.
        return $this->built[$releve->number] ??= json_encode(
            $this->build($releve),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_AMP,
        );
    }

    public function build(Releve $releve): \stdClass
    {
        $file = fn (string $suffix): string => $releve->dir.'/'.$releve->date.'.'.$suffix;

        $domains = $this->slurp($file('domains.json'))[0] ?? throw new \UnexpectedValueException("{$file('domains.json')}: missing");
        $meta = is_file($file('meta.json')) ? $this->slurp($file('meta.json'))[0] : new \stdClass();
        $results = $this->slurp($file('jsonl'));
        $self = $this->slurp($file('self.jsonl'));
        // cost.sh may still be running: only complete lines count (build.sh's grep '}$').
        $costs = $this->slurp($file('cost.jsonl'), completeOnly: true);
        $selfCosts = $this->slurp($file('self.cost.jsonl'), completeOnly: true);
        $pages = $this->slurp($file('csp.jsonl'));
        // Relevé 2 on: AI providers (ai.sh), Hugging Face (hf.sh), mail (mail.sh);
        // relevé 4 on: who holds the key (cle.sh); relevé 6 on: the third
        // parties each home page loads (tiers.sh). Optional.
        $extra = [];
        foreach (['ai', 'hf', 'mail', 'cle', 'tiers'] as $key) {
            if (is_file($file($key.'.jsonl'))) {
                foreach ($this->slurp($file($key.'.jsonl'), completeOnly: true) as $row) {
                    $extra[$key][$row->host] = (object) [$key => $row->{$key} ?? null];
                }
            }
        }
        $selfPages = $this->slurp($file('self.csp.jsonl'));
        // Relevé 3 on: the exchangers' TLS (mail-starttls.sh, from the server), by MX name.
        // ($mailtls | map({(.mx): (. | del(.mx))}) | add // {}) as $tlsBy
        $tlsBy = [];
        foreach ($this->slurp($file('mailtls.jsonl'), completeOnly: true) as $t) {
            $tlsBy[$t->mx] = $this->without($t, 'mx');
        }

        // ($c | map({(.host): {cost}}) | add // {}) as $cost
        $cost = [];
        foreach ($costs as $c) {
            $cost[$c->host] = (object) ['cost' => $c->cost ?? null];
        }
        // ($p | map({(.host): {page: del(.host)}}) | add) as $page
        $page = [];
        foreach ($pages as $p) {
            $page[$p->host] = (object) ['page' => $this->without($p, 'host')];
        }
        // ($r | map({(.host): (. + ($cost[.host] // {cost: null}) + ($page[.host] // {page: null}))}) | add) as $by
        $by = [];
        foreach ($results as $r) {
            // ... + ($aiBy[.host] // {}) + ($hfBy[.host] // {}) + ($mailBy[.host] // {}) + ($cleBy[.host] // {}) + ($tiersBy[.host] // {})
            $by[$r->host] = $this->merge($r, $cost[$r->host] ?? (object) ['cost' => null], $page[$r->host] ?? (object) ['page' => null],
                $extra['ai'][$r->host] ?? new \stdClass(), $extra['hf'][$r->host] ?? new \stdClass(), $extra['mail'][$r->host] ?? new \stdClass(), $extra['cle'][$r->host] ?? new \stdClass(), $extra['tiers'][$r->host] ?? new \stdClass());
        }

        $groups = [];
        foreach ($domains->groups as $g) {
            $entries = [];
            foreach ($g->entries as $e) {
                $entry = $this->merge($e, $by[$e->host] ?? (object) ['reachable' => false]);
                // if (.mail? and ((.mail.mx // []) | length) > 0 and ($tlsBy | length) > 0) then .mail.tls = ($tlsBy[.mail.mx[0]] // null) else . end
                if (isset($entry->mail) && $entry->mail instanceof \stdClass && [] !== ($entry->mail->mx ?? []) && [] !== $tlsBy) {
                    $entry->mail = clone $entry->mail;
                    $entry->mail->tls = $tlsBy[$entry->mail->mx[0]] ?? null;
                }
                $entries[] = $entry;
            }
            $groups[] = (object) ['key' => $g->key ?? null, 'label' => $g->label ?? null, 'entries' => $entries];
        }

        return (object) [
            'date' => $releve->date,
            'meta' => $meta,
            // $self[0] + {cost: ($sc[0].cost // null), page: ($sp[0] | del(.host))}
            'self' => $this->merge($self[0] ?? new \stdClass(), (object) [
                'cost' => $selfCosts[0]->cost ?? null,
                'page' => isset($selfPages[0]) ? $this->without($selfPages[0], 'host') : null,
            ]),
            'groups' => $groups,
        ];
    }

    /**
     * jq's --slurpfile on a file of one JSON value per line.
     *
     * @return list<\stdClass>
     */
    private function slurp(string $path, bool $completeOnly = false): array
    {
        if (!is_file($path)) {
            if ($completeOnly) {
                return [];
            }
            throw new \UnexpectedValueException("$path: missing");
        }
        if (str_ends_with($path, '.json')) {
            return [json_decode((string) file_get_contents($path), false, 512, \JSON_THROW_ON_ERROR)];
        }
        $values = [];
        foreach (file($path, \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = rtrim($line, "\r");
            if ('' === trim($line) || ($completeOnly && !str_ends_with($line, '}'))) {
                continue;
            }
            $values[] = json_decode($line, false, 512, \JSON_THROW_ON_ERROR);
        }

        return $values;
    }

    /** jq's `a + b + …` on objects: b's keys replace a's in place, new keys go last. */
    private function merge(\stdClass ...$objects): \stdClass
    {
        $merged = new \stdClass();
        foreach ($objects as $object) {
            foreach (get_object_vars($object) as $key => $value) {
                $merged->{$key} = $value;
            }
        }

        return $merged;
    }

    private function without(\stdClass $object, string $key): \stdClass
    {
        $copy = clone $object;
        unset($copy->{$key});

        return $copy;
    }
}
