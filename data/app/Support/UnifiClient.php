<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Talks to two generations of UniFi's local API, both reachable through the
 * same UniFi OS reverse proxy with the same X-API-KEY header:
 *
 *  - the "classic" per-site REST API (proxy/network/api/s/{site}/...), used
 *    here for port forwarding since Ubiquiti has not yet exposed that in the
 *    official Integration API;
 *  - the official Integration API (proxy/network/integration/v1/...), used
 *    here for zone-based firewall policies/zones, which Ubiquiti *has*
 *    documented (see https://developer.ui.com/network).
 */
class UnifiClient
{
    public function __construct(private readonly Settings $settings)
    {
    }

    private function classicBase(): string
    {
        return 'https://' . $this->settings->host() . '/proxy/network/api';
    }

    private function v1Base(): string
    {
        return 'https://' . $this->settings->host() . '/proxy/network/integration/v1';
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'X-API-KEY' => $this->settings->apiKey(),
            'Accept' => 'application/json',
        ])
            ->withOptions(['verify' => $this->settings->verifyTls()])
            ->timeout(15);
    }

    private function unwrap(Response $response): array
    {
        if (!$response->successful()) {
            throw new UnifiApiException(
                "UniFi API request failed with status {$response->status()}: " . $this->summarizeError($response),
                $response->status(),
                $response->body(),
            );
        }

        $json = $response->json();

        if (is_array($json) && array_key_exists('data', $json)) {
            return (array) $json['data'];
        }

        return (array) $json;
    }

    /**
     * Pulls the actual reason out of a failed response so it ends up in
     * RuleRecord::last_error instead of just a bare status code. Tries the
     * classic API's meta.msg shape, then a few common REST error shapes,
     * then falls back to a truncated raw body.
     */
    private function summarizeError(Response $response): string
    {
        $json = $response->json();

        if (is_array($json)) {
            if (isset($json['meta']['msg'])) {
                return (string) $json['meta']['msg'];
            }

            foreach (['message', 'error', 'errors', 'detail'] as $key) {
                if (isset($json[$key])) {
                    return is_scalar($json[$key]) ? (string) $json[$key] : json_encode($json[$key]);
                }
            }
        }

        return substr($response->body(), 0, 500) ?: '(empty response body)';
    }

    // ---------------------------------------------------------------
    // Site / zone discovery (used by the admin "Discover" actions)
    // ---------------------------------------------------------------

    /** @return array<int, array{name: string, desc: string}> */
    public function listSitesClassic(): array
    {
        return $this->unwrap($this->http()->get($this->classicBase() . '/self/sites'));
    }

    /** @return array<int, array<string, mixed>> */
    public function listSitesV1(): array
    {
        return $this->unwrap($this->http()->get($this->v1Base() . '/sites'));
    }

    /** @return array<int, array{id: string, name: string}> */
    public function listFirewallZones(string $siteId): array
    {
        return $this->unwrap($this->http()->get($this->v1Base() . "/sites/{$siteId}/firewall/zones"));
    }

    // ---------------------------------------------------------------
    // Port forwarding (classic API)
    // ---------------------------------------------------------------

    public function listPortForwards(): array
    {
        $site = $this->settings->classicSiteName();

        return $this->unwrap($this->http()->get($this->classicBase() . "/s/{$site}/rest/portforward"));
    }

    public function createPortForward(array $payload): array
    {
        $site = $this->settings->classicSiteName();
        $created = $this->unwrap($this->http()->post($this->classicBase() . "/s/{$site}/rest/portforward", $payload));

        return $created[0] ?? $created;
    }

    public function updatePortForward(string $id, array $payload): array
    {
        $site = $this->settings->classicSiteName();

        return $this->unwrap($this->http()->put($this->classicBase() . "/s/{$site}/rest/portforward/{$id}", $payload));
    }

    public function deletePortForward(string $id): void
    {
        $site = $this->settings->classicSiteName();
        $this->unwrap($this->http()->delete($this->classicBase() . "/s/{$site}/rest/portforward/{$id}"));
    }

    /**
     * Builds a port-forward rule payload for a single Pterodactyl allocation.
     * `proto` "tcp_udp" opens both since Pterodactyl allocations don't track
     * a protocol themselves.
     */
    public function buildPortForwardPayload(string $name, string $destinationIp, int $port): array
    {
        return [
            'name' => $name,
            'enabled' => true,
            'pfwd_interface' => 'wan',
            'src' => 'any',
            'proto' => 'tcp_udp',
            'fwd' => $destinationIp,
            'fwd_port' => (string) $port,
            'dst_port' => (string) $port,
        ];
    }

    // ---------------------------------------------------------------
    // Firewall policies (official v1 Integration API, zone-based)
    // ---------------------------------------------------------------

    public function listFirewallPolicies(string $siteId): array
    {
        return $this->unwrap($this->http()->get($this->v1Base() . "/sites/{$siteId}/firewall/policies"));
    }

    public function createFirewallPolicy(string $siteId, array $payload): array
    {
        return $this->unwrap($this->http()->post($this->v1Base() . "/sites/{$siteId}/firewall/policies", $payload));
    }

    public function updateFirewallPolicy(string $siteId, string $id, array $payload): array
    {
        return $this->unwrap($this->http()->put($this->v1Base() . "/sites/{$siteId}/firewall/policies/{$id}", $payload));
    }

    public function deleteFirewallPolicy(string $siteId, string $id): void
    {
        $this->unwrap($this->http()->delete($this->v1Base() . "/sites/{$siteId}/firewall/policies/{$id}"));
    }

    /**
     * Builds an ALLOW policy payload for inbound WAN -> destinationIp:port traffic.
     *
     * Built directly from Ubiquiti's published OpenAPI spec
     * (developer.ui.com/network/{version}/openapi.json, components.schemas)
     * rather than guessed -- earlier field-by-field 400s came from an
     * initial best-effort version written before this was pulled. Key
     * things that weren't obvious from the docs page alone:
     *  - `action` requires `allowReturnTraffic` in addition to `type`.
     *  - `ipProtocolScope` is a required top-level object; there is no
     *    top-level `protocol`/`ipVersion`/`connectionStateType` at all.
     *  - IP/port matching are nested discriminated lists
     *    (`ipAddressFilter.items[].{type,value}`,
     *    `portFilter.items[].{type,value}`), not flat `ips`/`ports` arrays.
     *  - `source.trafficFilter` is optional -- omitting it matches any
     *    traffic from that zone, which is exactly "any WAN source" here.
     *
     * Deliberately not restricting to TCP/UDP specifically
     * (`ipProtocolScope.protocolFilter` would do that, via another nested
     * discriminated object) -- omitting it matches all protocols, a superset
     * that still covers TCP+UDP. Fine for this use case; tighten later if
     * wanted.
     */
    public function buildFirewallPolicyPayload(string $name, string $description, string $destinationIp, int $port): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'enabled' => true,
            'loggingEnabled' => false,
            'action' => [
                'type' => 'ALLOW',
                'allowReturnTraffic' => true,
            ],
            'ipProtocolScope' => [
                'ipVersion' => 'IPV4',
            ],
            'source' => [
                'zoneId' => $this->settings->wanZoneId(),
            ],
            'destination' => [
                'zoneId' => $this->settings->lanZoneId(),
                'trafficFilter' => [
                    'type' => 'IP_ADDRESS',
                    'ipAddressFilter' => [
                        'type' => 'IP_ADDRESSES',
                        'matchOpposite' => false,
                        'items' => [
                            ['type' => 'IP_ADDRESS', 'value' => $destinationIp],
                        ],
                    ],
                    'portFilter' => [
                        'type' => 'PORTS',
                        'matchOpposite' => false,
                        'items' => [
                            ['type' => 'PORT_NUMBER', 'value' => $port],
                        ],
                    ],
                ],
            ],
        ];
    }
}
