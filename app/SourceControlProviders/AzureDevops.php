<?php

namespace App\SourceControlProviders;

use App\Exceptions\FailedToDeployGitHook;
use App\Exceptions\FailedToDestroyGitHook;
use Exception;
use Illuminate\Support\Facades\Http;

class AzureDevops extends AbstractSourceControlProvider
{
    private const API_VERSION = "7.1";

    private const API_URL = 'https://dev.azure.com';

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
                ->get($this->getRepositoriesUrl());
        } catch (Exception) {
            return false;
        }

        return $res->successful();
    }

    public function getRepo(?string $repo = null): mixed
    {
        $res = Http::withHeaders($this->getAuthenticationHeaders())
            ->get($this->getRepositoriesUrl($repo));

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

    /**
     * @TODO Register webhook for every event
     */
    public function deployHook(string $repo, array $events, string $secret): array
    {
        $repository = $this->getRepo($repo);

        $projectId = $repository["project"]["id"];

        try {
            $response = Http::withHeaders($this->getAuthenticationHeaders())
                ->post($this->getHooksUrl(), [
                    "publisherId" => "tfs",
                    "eventType" => $events[0],
                    "resourceVersion" => "1.0",
                    "consumerId" => "webHooks",
                    "consumerActionId" => "httpRequest",
                    "consumerInputs" => [
                        "url" => url('/api/git-hooks?secret='.$secret)
                    ],
                    "publisherInputs" => [
                        "repository" => $repository["id"],
                        "projectId" => $projectId,
                    ]
                ]);

        } catch (Exception $e) {
            throw new FailedToDeployGitHook($e->getMessage());
        }

        if ($response->status() != 200) {
            throw new FailedToDeployGitHook($response->body());
        }

        return [
            'hook_id' => json_decode($response->body())->id,
            'hook_response' => json_decode($response->body()),
        ];
    }

    public function destroyHook(string $repo, string $hookId): void
    {
        try {
            $response = Http::withHeaders($this->getAuthenticationHeaders())
                ->delete($this->getHooksUrl($hookId));
        } catch (Exception $e) {
            throw new FailedToDestroyGitHook($e->getMessage());
        }

        if ($response->status() != 204) {
            throw new FailedToDestroyGitHook($response->body());
        }
    }

    public function getLastCommit(string $repo, string $branch): ?array
    {
        $res = Http::withHeaders($this->getAuthenticationHeaders())
            ->get($this->getRepositoriesUrl("$repo/commits?searchCriteria.itemVersionVersion==".$branch));

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

    private function getRepositoriesUrl(?string $urlString = null): string
    {
        $urlSegments[] = static::API_URL;
        $urlSegments[] = $this->data()['organization'];
        $urlSegments[] = $this->data()['project'];
        $urlSegments[] = "_apis/git/repositories";
        $urlSegments[] = $this->cleanUrlString($urlString);

        return $this->appendApiVersion(
            $this->generateUrlFromSegments($urlSegments)
        );
    }

    private function getHooksUrl(?string $urlString = null): string
    {
        $urlSegments[] = static::API_URL;
        $urlSegments[] = $this->data()['organization'];
        $urlSegments[] = "_apis/hooks/subscriptions";
        $urlSegments[] = $this->cleanUrlString($urlString);

        return $this->appendApiVersion(
            $this->generateUrlFromSegments($urlSegments)
        );
    }

    private function cleanUrlString(?string $urlString = null): string | null
    {
        if ($urlString) {
            $urlString = trim(trim($urlString, '/'));
        }

        return $urlString;
    }

    private function generateUrlFromSegments(array $urlSegments): string
    {
        $urlSegments = array_filter($urlSegments, fn ($segment) => $segment);

        return implode('/', $urlSegments);
    }

    private function appendApiVersion(string $url): string {
        return $url . "?api-version=".static::API_VERSION;
    }

    private function getAuthenticationHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$this->data()['token'],
        ];
    }
}
