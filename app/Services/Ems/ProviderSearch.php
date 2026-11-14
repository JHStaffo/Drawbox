<?php

namespace App\Services\Ems;

use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Searches the Virginia Office of EMS provider search
 * (vdhems.vdh.virginia.gov/emsapps/ProviderSearch.html), an Oracle APEX page
 * with Certification#, Last Name, First Name and Agency boxes and a Search
 * button. There's no published API, so this does what a browser does: open
 * the page, fill in the boxes found by their labels, press Search, and read
 * the results table (see ResultParser). When the results have no agency
 * column, each matched provider's own page is opened for its agencies.
 *
 * Each search uses a fresh APEX session (cookies kept only for that search).
 */
class ProviderSearch
{
    private CookieJar $jar;

    /** @var array<string, mixed> what happened on the last search, for "Try the search" */
    public array $trace = [];

    /** The search page and the results as the site sent them, for "Try the search". */
    public string $lastPage = '';

    public string $lastResult = '';

    public function __construct(private ?string $url = null)
    {
        $this->url ??= (string) config('services.va_ems.url');
    }

    /**
     * @return list<array{name: string, cert: ?string, level: ?string, expires: ?string, agencies: list<string>, first: ?string, last: ?string}>
     *
     * @throws SearchFailed
     */
    public function search(?string $first, ?string $last, ?string $cert = null): array
    {
        $this->jar = new CookieJar;
        $this->trace = ['steps' => []];

        $this->lastPage = $this->lastResult = '';
        $cert = $cert !== null ? strtoupper(trim($cert)) : null;
        [$pageUrl, $html] = $this->get($this->url);
        $this->lastPage = $html;
        $this->trace['page_bytes'] = strlen($html);
        $this->trace['region_codes'] = substr_count($html, 'UkVHSU9OIFRZUEV');
        $form = $this->form($html, $pageUrl);
        $this->trace['salt'] = ($form['fields']['pSalt'] ?? '') !== '' ? 'yes' : 'no';
        $this->trace['protected'] = ($form['fields']['pPageItemsProtected'] ?? '') !== '' ? 'yes' : 'no';
        $fields = $this->fieldsByLabel($html);
        $this->trace['boxes'] = array_keys($fields);

        $values = array_filter([
            'cert' => $cert,
            'last' => $cert ? null : $last,
            'first' => $cert ? null : $first,
        ], fn ($v) => $v !== null && $v !== '');
        foreach ($values as $what => $value) {
            if (! isset($fields[$what])) {
                throw new SearchFailed("The provider search has no {$what} box.");
            }
        }

        // The results region refreshes in the background (an APEX report
        // plugin call to wwv_flow.ajax) rather than by submitting the page.
        $region = self::reportRegion($html);
        $this->trace['request'] = $region ? 'region '.$region['id'] : $this->searchRequest($html);
        [$resultUrl, $resultHtml] = $region
            ? $this->refreshRegion($form, $fields, $values, $region)
            : $this->submit($form, $fields, $values, $html);
        $this->lastResult = $resultHtml;
        $parsed = ResultParser::parse($resultHtml);
        $this->trace['columns'] = $parsed['columns'];
        $this->trace['headings'] = $parsed['headings'];
        $this->trace['bytes'] = strlen($resultHtml);
        $this->trace['rows'] = count($parsed['rows']);
        if (! $parsed['table'] && ! ResultParser::saysNoData($resultHtml)) {
            throw new SearchFailed("Couldn't find the results on the provider search.");
        }

        $providers = ResultParser::providers($parsed);
        // No agency column: open each provider's own page for their agencies.
        if ($parsed['table'] && ! in_array('agency', $parsed['columns'], true)) {
            foreach ($providers as &$provider) {
                if ($provider['link']) {
                    [, $detail] = $this->get($this->absolute($provider['link'], $resultUrl));
                    $provider['agencies'] = ResultParser::agenciesOnDetailPage($detail);
                }
            }
            unset($provider);
        }

        return array_map(fn ($p) => array_diff_key($p, ['link' => 1]), $providers);
    }

    private function http(): PendingRequest
    {
        return Http::withOptions(['cookies' => $this->jar, 'allow_redirects' => ['max' => 10, 'track_redirects' => true]])
            ->timeout(45)
            ->withUserAgent('Mozilla/5.0 (compatible; Fast Track Pulse affiliation check)')
            ->withHeaders(['Accept' => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8']);
    }

    /** @return array{0: string, 1: string} the final URL after redirects, and the page */
    private function get(string $url): array
    {
        try {
            $response = $this->http()->get($url);
        } catch (\Throwable $e) {
            report($e);
            throw new SearchFailed("Couldn't reach the provider search.");
        }

        return [$this->finalUrl($response, $url), $this->body($response)];
    }

    private function body(Response $response): string
    {
        $this->trace['steps'][] = $response->status();
        if (! $response->successful()) {
            throw new SearchFailed("The provider search answered with error {$response->status()}.");
        }

        return (string) $response->body();
    }

    private function finalUrl(Response $response, string $url): string
    {
        $history = $response->header('X-Guzzle-Redirect-History');

        return $history ? trim(last(explode(',', $history))) : $url;
    }

    /**
     * The page's form: where it posts and every field it would send.
     *
     * @return array{action: string, fields: array<string, string>}
     */
    private function form(string $html, string $pageUrl): array
    {
        $xpath = self::xpath($html);
        $form = $xpath->query('//form[@id="wwvFlowForm"]')->item(0) ?? $xpath->query('//form')->item(0);
        if (! $form instanceof DOMElement) {
            throw new SearchFailed("Couldn't find the search form on the provider search.");
        }
        $fields = [];
        // APEX 18+ writes pSalt and pPageItemsProtected as hidden inputs with
        // an id and no name, so take those by id (anywhere on the page).
        foreach ($xpath->query('//input[@id and not(@name)]') as $input) {
            /** @var DOMElement $input */
            if (in_array(strtolower($input->getAttribute('type')), ['hidden', ''], true)) {
                $fields[$input->getAttribute('id')] = $input->getAttribute('value');
            }
        }
        foreach ($xpath->query('.//input[@name]', $form) as $input) {
            /** @var DOMElement $input */
            $type = strtolower($input->getAttribute('type'));
            if (in_array($type, ['submit', 'button', 'image', 'file'], true)) {
                continue;
            }
            if (in_array($type, ['checkbox', 'radio'], true) && ! $input->hasAttribute('checked')) {
                continue;
            }
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        foreach ($xpath->query('//select[@name or @id]') as $select) {
            /** @var DOMElement $select */
            $selected = $xpath->query('.//option[@selected]', $select)->item(0) ?? $xpath->query('.//option', $select)->item(0);
            $fields[$select->getAttribute('name') ?: $select->getAttribute('id')] = $selected instanceof DOMElement ? $selected->getAttribute('value') : '';
        }

        return ['action' => $this->absolute($form->getAttribute('action') ?: 'wwv_flow.accept', $pageUrl), 'fields' => $fields];
    }

    /**
     * The search boxes, found by their labels.
     *
     * @return array<string, array{id: string, name: string}> cert, last, first, agency
     */
    private function fieldsByLabel(string $html): array
    {
        $xpath = self::xpath($html);
        $found = [];
        foreach ($xpath->query('//label[@for]') as $label) {
            /** @var DOMElement $label */
            $text = strtolower(preg_replace('/\s+/', ' ', trim($label->textContent)));
            $what = match (true) {
                str_contains($text, 'cert') => 'cert',
                str_contains($text, 'last') => 'last',
                str_contains($text, 'first') => 'first',
                str_contains($text, 'agency') => 'agency',
                default => null,
            };
            if (! $what || isset($found[$what])) {
                continue;
            }
            $id = $label->getAttribute('for');
            $input = $xpath->query('//*[@id="'.$id.'"]')->item(0);
            $name = $input instanceof DOMElement && $input->getAttribute('name') !== '' ? $input->getAttribute('name') : $id;
            $found[$what] = ['id' => $id, 'name' => $name];
            if ($input instanceof DOMElement && $xpath->query('.//option[@value="ALL"]', $input)->length) {
                $found[$what]['all'] = 'ALL';
            }
        }

        return $found;
    }

    /**
     * Press Search. Newer APEX (18 and later) reads the page items from a
     * p_json field; older APEX reads the form fields themselves. Both are sent.
     *
     * @return array{0: string, 1: string}
     */
    private function submit(array $form, array $fields, array $values, string $html): array
    {
        $post = $form['fields'];
        foreach ($fields as $what => $field) {
            $post[$field['name']] = $values[$what] ?? '';
        }
        $request = $this->searchRequest($html);
        $post['p_request'] = $request;
        $this->trace['request'] = $request;

        if (isset($post['pSalt'])) {
            $items = [];
            foreach (self::pageItems($html) as $id) {
                $match = collect($fields)->first(fn ($f) => $f['id'] === $id);
                $what = $match ? array_search($match, $fields, true) : null;
                $items[] = ['n' => $id, 'v' => $what ? ($values[$what] ?? '') : ($post[$id] ?? '')];
            }
            foreach ($fields as $what => $field) {
                if (! collect($items)->contains('n', $field['id'])) {
                    $items[] = ['n' => $field['id'], 'v' => $values[$what] ?? ''];
                }
            }
            $post['p_json'] = json_encode([
                'pageItems' => array_filter([
                    'itemsToSubmit' => $items,
                    'protected' => $post['pPageItemsProtected'] ?? '',
                    'rowVersion' => $post['pPageItemsRowVersion'] ?? '',
                    'formRegionChecksums' => [],
                ], fn ($v) => $v !== ''),
                'salt' => $post['pSalt'],
            ]);
        }

        try {
            $response = $this->http()->asForm()->post($form['action'], $post);
        } catch (\Throwable $e) {
            report($e);
            throw new SearchFailed("Couldn't reach the provider search.");
        }
        $body = $this->body($response);
        $url = $this->finalUrl($response, $form['action']);

        // Newer APEX answers a submit with {"redirectURL": "..."}.
        $json = json_decode($body, true);
        if (is_array($json)) {
            if (! empty($json['redirectURL'])) {
                return $this->get($this->absolute($json['redirectURL'], $form['action']));
            }
            $message = collect($json['errors'] ?? [])->pluck('message')->filter()->first();
            throw new SearchFailed($message ? 'The provider search said: '.strip_tags($message) : "The provider search didn't take the search.");
        }

        return [$url, $body];
    }

    /**
     * The report region the page refreshes: its ajax identifier and region id,
     * from the page's init script (`"ajaxIdentifier":"UkVHSU9OIFRZUEV-fjUw..."`,
     * which is base64 of "REGION TYPE~~<region id>" before the checksum).
     *
     * @return array{ajax: string, id: string}|null
     */
    public static function reportRegion(string $html): ?array
    {
        // The identifier may be in a script as JSON (an escaped slash or \u002F),
        // HTML-encoded in an attribute, or URL-encoded; find it by its base64
        // prefix ("REGION TYPE") wherever it is.
        preg_match_all('#(UkVHSU9OIFRZUEV[A-Za-z0-9_\-]+)(?:\\\\?/|\\\\u002[Ff]|%2[Ff]|&\#x2[Ff];|&\#47;)([A-Za-z0-9_\-]+)#', $html, $m, PREG_SET_ORDER);
        $regions = [];
        foreach ($m as $match) {
            $decoded = base64_decode(strtr($match[1], '-_', '+/'), false) ?: '';
            if (preg_match('/^REGION TYPE~~(\d+)/', $decoded, $id)) {
                $regions[$id[1]] ??= ['ajax' => $match[1].'/'.$match[2], 'id' => $id[1]];
            }
        }
        if (! $regions) {
            return null;
        }
        // More than one region: the results are the one drawn as a report (report_R<id>).
        foreach ($regions as $id => $region) {
            if (preg_match('/report_R?'.$id.'|R'.$id.'_(ir|data_panel)|interactiveReport[^;]{0,300}R?'.$id.'/', $html)) {
                return $region;
            }
        }

        return reset($regions);
    }

    /**
     * Refresh the results region the way the page's Search button does: post
     * the search boxes as page items to wwv_flow.ajax with the region's plugin
     * identifier. Answers with the region's HTML.
     *
     * @return array{0: string, 1: string}
     */
    private function refreshRegion(array $form, array $fields, array $values, array $region): array
    {
        $post = $form['fields'];
        $items = [];
        foreach (['cert', 'last', 'first', 'agency'] as $what) {
            if (isset($fields[$what])) {
                $field = $fields[$what];
                // Agency: every agency ("ALL"), as the page sends it by default.
                $items[] = ['n' => $field['id'], 'v' => $values[$what] ?? ($what === 'agency' ? ($field['all'] ?? $post[$field['name']] ?? '') : '')];
            }
        }
        $data = [
            'p_flow_id' => $post['p_flow_id'] ?? '',
            'p_flow_step_id' => $post['p_flow_step_id'] ?? '',
            'p_instance' => $post['p_instance'] ?? '',
            'p_debug' => '',
            'p_request' => 'PLUGIN='.$region['ajax'],
            'p_widget_action' => 'reset',
            'x01' => $region['id'],
            'p_json' => json_encode([
                'pageItems' => [
                    'itemsToSubmit' => $items,
                    'protected' => $post['pPageItemsProtected'] ?? '',
                    'rowVersion' => $post['pPageItemsRowVersion'] ?? '',
                    'formRegionChecksums' => [],
                ],
                'salt' => $post['pSalt'] ?? '',
            ]),
        ];
        $url = preg_replace('/wwv_flow\.accept.*$/', '', $form['action']).'wwv_flow.ajax?p_context='.$data['p_flow_id'].':'.$data['p_flow_step_id'].':'.$data['p_instance'];

        try {
            $response = $this->http()->asForm()
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, */*; q=0.01', 'Referer' => $form['action']])
                ->post($url, $data);
        } catch (\Throwable $e) {
            report($e);
            throw new SearchFailed("Couldn't reach the provider search.");
        }
        $body = $this->body($response);
        $json = json_decode($body, true);
        if (is_array($json)) {
            $message = collect($json['errors'] ?? [])->pluck('message')->filter()->first() ?? ($json['error'] ?? null);
            throw new SearchFailed($message ? 'The provider search said: '.strip_tags((string) $message) : "The provider search didn't take the search.");
        }

        return [$url, $body];
    }

    /** The request name the Search button sends, e.g. SEARCH. */
    private function searchRequest(string $html): string
    {
        $xpath = self::xpath($html);
        foreach ($xpath->query('//button | //input[@type="button" or @type="submit"] | //a[contains(@class,"t-Button")]') as $button) {
            /** @var DOMElement $button */
            $text = strtolower(trim($button->textContent ?: $button->getAttribute('value')));
            if (! str_contains($text, 'search') || str_contains($text, 'reset')) {
                continue;
            }
            $js = $button->getAttribute('onclick').' '.$button->getAttribute('href').' '.$button->getAttribute('data-request');
            if (preg_match('/(?:apex\.submit|doSubmit)\(\s*(?:\{[^}]*?request\s*:\s*)?[\'"]([A-Za-z0-9_\-]+)[\'"]/', $js, $m)) {
                return $m[1];
            }
            if ($button->getAttribute('data-request') !== '') {
                return $button->getAttribute('data-request');
            }
            $id = $button->getAttribute('id');
            // A dynamic action on the button: look for its submit in the page scripts.
            if ($id !== '' && preg_match('/'.preg_quote($id, '/').'.{0,400}?apex\.submit\(\s*(?:\{[^}]*?request\s*:\s*)?[\'"]([A-Za-z0-9_\-]+)[\'"]/s', $html, $m)) {
                return $m[1];
            }
            if ($id !== '' && ! preg_match('/^B\d+$/', $id)) {
                return $id; // APEX buttons submit their own name by default
            }
        }

        return 'SEARCH';
    }

    /** @return list<string> page item ids APEX lists for submitting (P3_LAST_NAME ...) */
    private static function pageItems(string $html): array
    {
        $xpath = self::xpath($html);
        $ids = [];
        foreach ($xpath->query('//*[@id][starts-with(@id,"P") and (self::input or self::select or self::textarea)]') as $item) {
            /** @var DOMElement $item */
            $id = $item->getAttribute('id');
            if (preg_match('/^P\d+_[A-Z0-9_]+$/', $id) && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function absolute(string $link, string $base): string
    {
        $link = html_entity_decode(trim($link));
        if (preg_match('#^https?://#i', $link)) {
            return $link;
        }
        $parts = parse_url($base);
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (str_starts_with($link, '/')) {
            return $origin.$link;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/');

        return $origin.$dir.preg_replace('#^\./#', '', $link);
    }

    public static function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($doc);
    }
}
