<?php

namespace Ado\Api;

use Ado\Config\Config;
use RuntimeException;

class AzureDevOps
{
    private string $baseUrl;
    private string $vsspsBaseUrl;
    private string $authHeader;

    /** @var list<array<string,mixed>>|null Cached graph users (lazy, all pages). */
    private ?array $graphUsers = null;

    public function __construct(private Config $config)
    {
        $org = $config->get('org');
        $pat = $config->get('pat');
        $this->baseUrl = "https://dev.azure.com/{$org}";
        $this->vsspsBaseUrl = "https://vssps.dev.azure.com/{$org}";
        $this->authHeader = 'Basic ' . base64_encode(":{$pat}");
    }

    // -------------------------------------------------------------------------
    // Projects
    // -------------------------------------------------------------------------

    public function getProjects(): array
    {
        return $this->get('_apis/projects?api-version=7.1')['value'] ?? [];
    }

    // -------------------------------------------------------------------------
    // Work items
    // -------------------------------------------------------------------------

    public function queryWorkItems(string $project, string $wiql, int $top = 50): array
    {
        $p = rawurlencode($project);
        $result = $this->post("{$p}/_apis/wit/wiql?api-version=7.1&\$top={$top}", ['query' => $wiql]);
        return array_column($result['workItems'] ?? [], 'id');
    }

    public function getWorkItem(string $project, int $id): array
    {
        $p = rawurlencode($project);
        return $this->get("{$p}/_apis/wit/workitems/{$id}?api-version=7.1");
    }

    public function getWorkItemWithRelations(string $project, int $id): array
    {
        $p = rawurlencode($project);
        $fields = 'System.Id,System.Title,System.WorkItemType,System.State,System.AssignedTo,'
            . 'System.CreatedBy,System.CreatedDate,System.ChangedDate,System.IterationPath,'
            . 'System.AreaPath,Microsoft.VSTS.Common.Priority,System.Description,'
            . 'Microsoft.VSTS.Common.AcceptanceCriteria';
        $item = $this->get("{$p}/_apis/wit/workitems/{$id}?api-version=7.1&fields={$fields}");
        $withRelations = $this->get("{$p}/_apis/wit/workitems/{$id}?api-version=7.1&\$expand=relations");
        $item['relations'] = $withRelations['relations'] ?? [];
        return $item;
    }

    public function getWorkItemComments(string $project, int $id): array
    {
        $p = rawurlencode($project);
        $data = $this->get("{$p}/_apis/wit/workitems/{$id}/comments?api-version=7.1-preview.3");
        return $data['comments'] ?? [];
    }

    /**
     * Post a comment via the dedicated comments API. Unlike a System.History
     * patch, this endpoint triggers @mention notifications in ADO.
     */
    public function addComment(string $project, int $id, string $html): array
    {
        $p = rawurlencode($project);
        return $this->post(
            "{$p}/_apis/wit/workItems/{$id}/comments?api-version=7.1-preview.3",
            ['text' => $html]
        );
    }

    /**
     * Resolve a user's display name to their tfid (storageKey GUID), which is the
     * value required by data-vss-mention. Returns null if no user matches.
     *
     * Flow: graph/users -> match displayName -> descriptor
     *       -> graph/storagekeys/{descriptor} -> value (tfid).
     */
    public function resolveUserToTfid(string $displayName): ?string
    {
        $descriptor = null;
        foreach ($this->graphUsers() as $user) {
            if (($user['displayName'] ?? null) === $displayName) {
                $descriptor = $user['descriptor'] ?? null;
                break;
            }
        }

        if ($descriptor === null) {
            return null;
        }

        $key = $this->requestUrl(
            'GET',
            "{$this->vsspsBaseUrl}/_apis/graph/storagekeys/" . rawurlencode($descriptor)
                . '?api-version=7.1-preview.1'
        )['data'];

        return $key['value'] ?? null;
    }

    /**
     * Fetch (and cache) all graph users, following continuation-token pagination.
     *
     * @return list<array<string,mixed>>
     */
    private function graphUsers(): array
    {
        if ($this->graphUsers !== null) {
            return $this->graphUsers;
        }

        $users = [];
        $url = "{$this->vsspsBaseUrl}/_apis/graph/users?api-version=7.1-preview.1";
        do {
            $response = $this->requestUrl('GET', $url);
            foreach ($response['data']['value'] ?? [] as $user) {
                $users[] = $user;
            }
            $token = $response['headers']['x-ms-continuationtoken'] ?? null;
            $url = $token
                ? "{$this->vsspsBaseUrl}/_apis/graph/users?api-version=7.1-preview.1"
                    . '&continuationToken=' . rawurlencode($token)
                : null;
        } while ($url !== null);

        return $this->graphUsers = $users;
    }

    public function getWorkItemsBatch(string $project, array $ids, array $fields = []): array
    {
        if (empty($ids)) {
            return [];
        }
        $p = rawurlencode($project);
        $params = 'ids=' . implode(',', $ids) . '&api-version=7.1';
        if ($fields) {
            $params .= '&fields=' . implode(',', $fields);
        }
        return $this->get("{$p}/_apis/wit/workitems?{$params}")['value'] ?? [];
    }

    public function getWorkItemsBatchWithRelations(string $project, array $ids, array $fields = []): array
    {
        if (empty($ids)) {
            return [];
        }
        // Fetch fields and relations separately, then merge
        $withFields = $this->getWorkItemsBatch($project, $ids, $fields);

        $p = rawurlencode($project);
        $chunks = array_chunk($ids, 200);
        $relations = [];
        foreach ($chunks as $chunk) {
            $params = 'ids=' . implode(',', $chunk) . '&$expand=relations&api-version=7.1';
            $items = $this->get("{$p}/_apis/wit/workitems?{$params}")['value'] ?? [];
            foreach ($items as $item) {
                $relations[$item['id']] = $item['relations'] ?? [];
            }
        }

        foreach ($withFields as &$item) {
            $item['relations'] = $relations[$item['id']] ?? [];
        }

        return $withFields;
    }

    public function createWorkItem(string $project, string $type, array $patch): array
    {
        $p = rawurlencode($project);
        $encodedType = rawurlencode($type);
        return $this->patch(
            "{$p}/_apis/wit/workitems/\${$encodedType}?api-version=7.1",
            $patch,
            'application/json-patch+json',
            'POST'
        );
    }

    public function updateWorkItem(string $project, int $id, array $patch): array
    {
        $p = rawurlencode($project);
        return $this->patch(
            "{$p}/_apis/wit/workitems/{$id}?api-version=7.1",
            $patch,
            'application/json-patch+json'
        );
    }

    public function getWorkItemTypes(string $project): array
    {
        $p = rawurlencode($project);
        return $this->get("{$p}/_apis/wit/workitemtypes?api-version=7.1")['value'] ?? [];
    }

    public function getWorkItemStates(string $project, string $type): array
    {
        $p = rawurlencode($project);
        $encoded = rawurlencode($type);
        return $this->get("{$p}/_apis/wit/workitemtypes/{$encoded}/states?api-version=7.1")['value'] ?? [];
    }

    // -------------------------------------------------------------------------
    // Teams
    // -------------------------------------------------------------------------

    public function getTeams(string $project): array
    {
        $p = rawurlencode($project);
        return $this->get("_apis/projects/{$p}/teams?api-version=7.1&\$top=100")['value'] ?? [];
    }

    public function getTeamAreaPaths(string $project, string $team): array
    {
        $p = rawurlencode($project);
        $t = rawurlencode($team);
        $data = $this->get("{$p}/{$t}/_apis/work/teamsettings/teamfieldvalues?api-version=7.1");
        return $data['values'] ?? [];
    }

    // -------------------------------------------------------------------------
    // Iterations / Sprints
    // -------------------------------------------------------------------------

    public function getIterations(string $project, string $team = ''): array
    {
        $p = rawurlencode($project);
        $teamSegment = $team ? rawurlencode($team) . '/' : '';
        try {
            return $this->get("{$p}/{$teamSegment}_apis/work/teamsettings/iterations?api-version=7.1")['value'] ?? [];
        } catch (RuntimeException) {
            return $this->get("{$p}/_apis/work/teamsettings/iterations?api-version=7.1")['value'] ?? [];
        }
    }

    public function getAllIterations(string $project): array
    {
        $p = rawurlencode($project);
        $tree = $this->get("{$p}/_apis/wit/classificationnodes/iterations?\$depth=10&api-version=7.1");
        $flat = [];
        $this->flattenIterations($tree, $flat);
        return $flat;
    }

    private function flattenIterations(array $node, array &$flat): void
    {
        if (isset($node['attributes'])) {
            $flat[] = $node;
        }
        foreach ($node['children'] ?? [] as $child) {
            $this->flattenIterations($child, $flat);
        }
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    private function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    private function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    private function patch(string $path, array $body, string $contentType = 'application/json', string $method = 'PATCH'): array
    {
        return $this->request($method, $path, $body, $contentType);
    }

    private function request(string $method, string $path, array $body = [], string $contentType = 'application/json'): array
    {
        return $this->requestUrl($method, "{$this->baseUrl}/{$path}", $body, $contentType)['data'];
    }

    /**
     * Perform a request against an absolute URL (used for the vssps graph host as
     * well as the default org host). Returns both the decoded body and the
     * lower-cased response headers so callers can read pagination tokens.
     *
     * @return array{data: array<mixed>, headers: array<string,string>}
     */
    private function requestUrl(string $method, string $url, array $body = [], string $contentType = 'application/json'): array
    {
        $ch = curl_init($url);

        $headers = [
            "Authorization: {$this->authHeader}",
            "Content-Type: {$contentType}",
            "Accept: application/json",
        ];

        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$responseHeaders): int {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($header);
            },
        ]);

        if ($body) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException("cURL error: {$error}");
        }

        $data = json_decode($response, true);

        if ($status >= 400) {
            $message = $data['message'] ?? $data['errorCode'] ?? substr($response, 0, 200);
            throw new RuntimeException("API {$status}: {$message}");
        }

        return ['data' => $data ?? [], 'headers' => $responseHeaders];
    }
}
