#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Viceroy\Connections\llamacppOAICompatibleConnection;

class GitHubPRReviewer
{
    private $config;
    private $llm;

    public function __construct()
    {
        $this->loadEnvironment();
        $this->validateConfig();
    }

    private function loadEnvironment()
    {
        $dotenv = Dotenv::createImmutable(__DIR__);
        $dotenv->load();
    }

    private function validateConfig()
    {
        $required = ['GITHUB_TOKEN', 'REPO_OWNER', 'REPO_NAME'];
        foreach ($required as $var) {
            if (!isset($_ENV[$var])) {
                $this->error("Missing required environment variable: $var");
            }
        }
    }

    public function run()
    {
        try {
            $pr = $this->selectPR();
            $diff = $this->fetchDiff($pr['diff_url']);
            $review = $this->analyzeDiff($diff);
            $this->displayReview($review);

            if (!empty($review['code_snippets'])) {
                $this->handleGitOperations($pr, $review);
            }
        } catch (Exception $e) {
            $this->error($e->getMessage());
        }
    }

    private function selectPR()
    {
        $prs = $this->githubRequest("/repos/{$_ENV['REPO_OWNER']}/{$_ENV['REPO_NAME']}/pulls");
        $this->displayPRList($prs);
        return $this->promptPRSelection($prs);
    }

    private function displayPRList(array $prs)
    {
        $this->output("\nOpen Pull Requests:", 'green');
        foreach ($prs as $i => $pr) {
            // Display PR basic info
            $this->output(sprintf(
                "%d. #%d: %s\n   Created: %s by %s",
                $i + 1,
                $pr['number'],
                $pr['title'],
                date('Y-m-d H:i:s', strtotime($pr['created_at'])),
                $pr['user']['login']
            ), 'yellow');

            // Fetch and display commits
            $commits = $this->githubRequest($pr['commits_url']);
            if (!empty($commits)) {
                $this->output("   Commits:", 'green');
                foreach ($commits as $commit) {
                    $commitDetails = $this->githubRequest($commit['url']);
                    $this->output(sprintf(
                        "   - %s: %s\n     by %s (%s)",
                        substr($commitDetails['sha'], 0, 7),
                        $commitDetails['commit']['message'],
                        $commitDetails['author']['login'] ?? $commitDetails['commit']['author']['name'],
                        date('Y-m-d H:i:s', strtotime($commitDetails['commit']['author']['date']))
                    ), 'yellow');
                }
            } else {
                $this->output("   No commits found", 'green');
            }

            $this->output(""); // Empty line between PRs
        }
    }

    private function githubRequest($endpoint, $data = null, $method = 'GET')
    {
        $url = str_starts_with($endpoint, 'http') ? $endpoint : "https://api.github.com$endpoint";
        $ch = curl_init($url);
        $headers = [
            'Authorization: token ' . $_ENV['GITHUB_TOKEN'],
            'User-Agent: PHP PR Reviewer',
            'Accept: application/vnd.github.v3+json'
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers
        ]);

        if ($data) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $headers[] = 'Content-Type: application/json';
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode >= 400) {
            throw new Exception("GitHub API error ($httpCode): $response");
        }

        curl_close($ch);
        return json_decode($response, true);
    }

    private function promptPRSelection(array $prs)
    {
        $this->output("\nEnter PR number to review: ", 'green');
        return reset($prs); // Remove this line to enable user selection
        $selected = (int)fgets(STDIN);
        if ($selected < 1 || $selected > count($prs)) {
            throw new Exception("Invalid PR selection");
        }
        return $prs[$selected - 1];
    }

    private function fetchDiff($diffUrl)
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $diffUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: token ' . $_ENV['GITHUB_TOKEN'],
                'User-Agent: PHP PR Reviewer',
                'Accept: application/vnd.github.v3.diff'
            ]
        ]);

        $diff = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode !== 200) {
            curl_close($ch);
            throw new Exception("Failed to fetch diff. HTTP Code: {$httpCode}");
        }
        curl_close($ch);
        return $diff;
    }

    private function analyzeDiff($diff)
{
    $this->llm = new llamacppOAICompatibleConnection();
    $this->llm->setGuzzleConnectionTimeout(300);

    if (!$this->llm->health()) {
        throw new Exception("LLM endpoint is unavailable");
    }

    // Updated system message with XML structure
    $this->llm->getRolesManager()
        ->setSystemMessage("You are a highly skilled code reviewer and software engineer. Analyze the provided code diff thoroughly and provide the following in XML format:

<review>
  <summary>A concise summary of the actual changes in the diff</summary>
  <issues>
    <issue>Current issue in the diff</issue>
  </issues>
  <changes>
    <change>Improvement being implemented now (must have code snippet)</change>
  </changes>
  <recommendations>
    <recommendation>Future improvement suggestion</recommendation>
  </recommendations>
  <code_snippets>
    <snippet><![CDATA[Valid unescaped Git unified diff]]></snippet>
  </code_snippets>
</review>

**Key Requirements**:
- `changes` must only contain improvements being implemented in this commit
- Each `change` must have a corresponding `snippet`
- `recommendations` are for future consideration only
- Wrap code snippets in CDATA sections");

    $this->llm->getRolesManager()->addMessage('user', $diff);

    $this->output("\nCalling LLM for analysis...", 'cyan');
    $startTime = microtime(true);
    $response = $this->llm->queryPost();
    $endTime = microtime(true);
    $this->output(sprintf("LLM call completed in %.2f seconds.", $endTime - $startTime), 'cyan');

    if (!$response) {
        throw new Exception("Failed to get LLM response");
    }

    $responseTxt = $this->clean_xml_response($response->getLlmResponse());
    $xml = simplexml_load_string($responseTxt);

    if ($xml === false) {
        throw new Exception("Invalid XML response from LLM");
    }

    // Parse XML into structured review data
    $review = [
        'summary' => (string)$xml->summary,
        'issues' => [],
        'changes' => [],
        'recommendations' => [],
        'code_snippets' => []
    ];

    foreach ($xml->issues->issue as $issue) {
        $review['issues'][] = (string)$issue;
    }

    foreach ($xml->changes->change as $change) {
        $review['changes'][] = (string)$change;
    }

    foreach ($xml->recommendations->recommendation as $rec) {
        $review['recommendations'][] = (string)$rec;
    }

    foreach ($xml->code_snippets->snippet as $snippet) {
        $review['code_snippets'][] = (string)$snippet;
    }

    // Validation remains similar but checks changes instead of improvements
    if (empty($review['code_snippets'])) {
        throw new Exception("LLM response missing code snippets");
    }

    foreach ($review['code_snippets'] as $snippet) {
        var_dump($snippet);
        if (strpos($snippet, 'diff --git') !== 0) {
            throw new Exception("Invalid diff format in code snippets");
        }
    }

    return $review;
}

private function displayReview(array $review)
{
    $this->output("\nReview Summary:", 'green');
    $this->output($review['summary'] . "\n");

    if (!empty($review['issues'])) {
        $this->output("Critical Issues Found:", 'red');
        foreach ($review['issues'] as $issue) {
            $this->output("- $issue", 'red');
        }
    }

    if (!empty($review['changes'])) {
        $this->output("\nChanges Being Implemented:", 'cyan');
        foreach ($review['changes'] as $change) {
            $this->output("- $change", 'cyan');
        }
    }

    if (!empty($review['recommendations'])) {
        $this->output("\nFuture Recommendations:", 'magenta');
        foreach ($review['recommendations'] as $rec) {
            $this->output("- $rec", 'magenta');
        }
    }
}

private function handleGitOperations($pr, array $review)
{
    // Update references from improvements to changes
    if (!empty($review['changes'])) {
        $this->output("\nThese changes will implement:", 'yellow');
        foreach ($review['changes'] as $change) {
            $this->output("- $change", 'yellow');
        }
    }

    // Rest of the method remains the same...
}

private function clean_xml_response($response)
{
    if (preg_match('/```xml\s*(.*?)\s*```/s', $response, $matches)) {
        $response = $matches[1];
    }
    return trim($response);
}

    private function applyPatchInMemory($originalContent, $diff)
{
    // Split the original content into lines
    $originalLines = explode("\n", $originalContent);
    $diffLines = explode("\n", $diff);

    $result = [];
    $linePointer = 0;

    foreach ($diffLines as $line) {
        // Detect the start of a hunk
        if (preg_match('/^@@ -(\d+),?(\d*) \+(\d+),?(\d*) @@/', $line, $matches)) {
            $linePointer = (int)$matches[1] - 1; // Adjust to zero-based index
            continue;
        }

        // Handle context lines (unchanged lines)
        if (str_starts_with($line, ' ')) {
            if (isset($originalLines[$linePointer])) {
                $result[] = $originalLines[$linePointer];
            }
            $linePointer++;
        }

        // Handle removed lines
        if (str_starts_with($line, '-')) {
            $linePointer++; // Skip the line in the original content
        }

        // Handle added lines
        if (str_starts_with($line, '+')) {
            $result[] = substr($line, 1); // Add the new line
        }
    }

    // Ensure proper newline at the end of the file
    $content = implode("\n", $result);
    if (!str_ends_with($content, "\n")) {
        $content .= "\n";
    }

    return $content;
}

    private function extractFilePathFromDiff($diff)
    {
        if (preg_match('/^diff --git a\/(.+?) b\//', $diff, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function fetchFileContent($owner, $repo, $path, $branch)
    {
        $url = "https://api.github.com/repos/$owner/$repo/contents/" . urlencode($path) . "?ref=" . urlencode($branch);
        $response = $this->githubRequest($url);

        if (isset($response['content'])) {
            return base64_decode($response['content']);
        }
        return null;
    }

    private function createCommit($owner, $repo, $branch, $files, $message)
    {
        $baseTree = $this->getBaseTree($owner, $repo, $branch);
        $blobs = $this->createBlobs($owner, $repo, $files);
        $treeSha = $this->createTree($owner, $repo, $baseTree, $blobs);
        $commitSha = $this->createCommitObject($owner, $repo, $message, $treeSha, $branch);
        $this->updateBranch($owner, $repo, $branch, $commitSha);

        $this->output("Successfully created new commit: $commitSha", 'green');
    }

    private function getBaseTree($owner, $repo, $branch)
    {
        $ref = $this->githubRequest("/repos/$owner/$repo/git/ref/heads/" . urlencode($branch));
        $commit = $this->githubRequest($ref['object']['url']);
        return $commit['tree']['sha'];
    }

    private function createBlobs($owner, $repo, $files)
    {
        $blobs = [];
        foreach ($files as $path => $content) {
            $blob = $this->githubRequest("/repos/$owner/$repo/git/blobs", [
                'content' => base64_encode($content),
                'encoding' => 'base64'
            ], 'POST');
            $blobs[$path] = $blob['sha'];
        }
        return $blobs;
    }

    private function createTree($owner, $repo, $baseTree, $blobs)
    {
        $tree = [];
        foreach ($blobs as $path => $sha) {
            $tree[] = [
                'path' => $path,
                'mode' => '100644',
                'type' => 'blob',
                'sha' => $sha
            ];
        }

        $response = $this->githubRequest("/repos/$owner/$repo/git/trees", [
            'base_tree' => $baseTree,
            'tree' => $tree
        ], 'POST');

        return $response['sha'];
    }

    private function createCommitObject($owner, $repo, $message, $treeSha, $branch)
    {
        $parentSha = $this->githubRequest("/repos/$owner/$repo/git/ref/heads/" . urlencode($branch))['object']['sha'];

        $commit = $this->githubRequest("/repos/$owner/$repo/git/commits", [
            'message' => $message,
            'tree' => $treeSha,
            'parents' => [$parentSha]
        ], 'POST');

        return $commit['sha'];
    }

    private function updateBranch($owner, $repo, $branch, $commitSha)
    {
        $this->githubRequest("/repos/$owner/$repo/git/refs/heads/" . urlencode($branch), [
            'sha' => $commitSha
        ], 'PATCH');
    }

    private function confirmAction($prompt)
    {
        $this->output("\n$prompt (y/N): ", 'yellow');
        if (trim(fgets(STDIN)) !== 'y') {
            throw new Exception("User aborted operation");
        }
    }

    private function output($message, $color = null)
    {
        echo $color ? $this->colorize($message, $color) : $message;
        echo PHP_EOL;
    }

    private function error($message)
    {
        $this->output("Error: $message", 'red');
        exit(1);
    }

    private function colorize($text, $color = null)
    {
        $colors = [
            'green' => "\033[32m",
            'yellow' => "\033[33m",
            'red' => "\033[31m",
            'cyan' => "\033[36m",
            'reset' => "\033[0m",
        ];

        if (!isset($colors[$color])) {
            return $text; // Return uncolored text if color is not defined
        }

        return $colors[$color] . $text . $colors['reset'];
    }


}

(new GitHubPRReviewer())->run();
