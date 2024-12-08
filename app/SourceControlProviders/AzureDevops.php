<?php

namespace App\SourceControlProviders;

use Exception;
use Illuminate\Support\Facades\Http;

class AzureDevops extends AbstractSourceControlProvider
{

    private const API_VERSION = "4.1";

    protected string $apiUrl = 'https://dev.azure.com';

    public function createRules(array $input): array
    {
        return [
            ...parent::createRules($input),
            'organization' => 'required',
            'project' => 'required',
        ];
    }

    public function createData(array $input): array
    {
        return [
            ...parent::createData($input),
            'organization' => $input['organization'] ?? '',
            'project' => $input['project'] ?? ''
        ];
    }

    public function data(): array
    {
        return [
            ...parent::data(),
            'organization' => $this->sourceControl->provider_data['organization'] ?? '',
            'project' => $this->sourceControl->provider_data['project'] ?? '',
        ];
    }

    public function connect(): bool
    {
        try {
            $res = Http::withHeaders($this->getAuthenticationHeaders())
                ->get($this->getUrl("repositories"));
        } catch (Exception) {
            return false;
        }

        return $res->successful();
    }

    public function getRepo(?string $repo = null): mixed
    {
        $res = Http::withHeaders($this->getAuthenticationHeaders())
            ->get($this->getUrl("/repositories/$repo"));

        $this->handleResponseErrors($res, $repo);

        return $res->json();
    }

    public function fullRepoUrl(string $repo, string $key): string
    {
        $organization = $this->data()['organization'];
        $token = $this->data()['token'];
        $project = $this->data()['project'];

        return sprintf('https://%s:%s@dev.azure.com/%s/%s/_git/%s', $key, $token, $organization, $project, $repo);
    }

    public function deployHook(string $repo, array $events, string $secret): array
    {
        // TODO: Implement deployHook() method.
    }

    public function destroyHook(string $repo, string $hookId): void
    {
        // TODO: Implement destroyHook() method.
    }

    public function getLastCommit(string $repo, string $branch): ?array
    {
        $res = Http::withHeaders($this->getAuthenticationHeaders())
            ->get($this->getUrl("/repositories/$repo/commits?searchCriteria.itemVersionVersion==".$branch));

        $this->handleResponseErrors($res, $repo);

        $commits = $res->json();

        if (isset($commits['value']) && $commits['count'] > 0) {
            $lastCommit = $commits['value'][0];

            return [
                'commit_id' => $lastCommit['commitId'],
                'commit_data' => [
                    'name' => $lastCommit['author']['name'] ?? null,
                    'email' => $lastCommit['author']['email'] ?? null,
                    'message' => str_replace("\n", '', $lastCommit['comment']),
                    'url' => $lastCommit['remoteUrl'] ?? null,
                ],
            ];
        }

        return null;
    }

    public function deployKey(string $title, string $repo, string $key): void
    {
        // TODO: Implement deployKey() method.
    }

    private function getUrl(string $urlString): string
    {
        $urlString = trim($urlString);
        $urlString = trim($urlString, '/');

        $urlSegments[] = $this->apiUrl;
        $urlSegments[] = $this->data()['organization'];
        $urlSegments[] = $this->data()['project'];
        $urlSegments[] = "_apis/git/$urlString?api-version=".static::API_VERSION;

        $urlSegments = array_filter($urlSegments, fn ($segment) => $segment);

        return implode('/', $urlSegments);
    }

    private function getAuthenticationHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$this->data()['token'],
        ];
    }
}
