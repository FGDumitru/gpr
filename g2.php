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

        // Improved system message
        $this->llm->getRolesManager()
            ->setSystemMessage("You are a highly skilled code reviewer and software engineer. Analyze the provided code diff thoroughly and provide the following in JSON format:
            
            1. **summary**: A concise summary of the changes in the diff. Focus on what was actually modified, added, or removed. Avoid speculative or future considerations.
            
            2. **issues**: A list of specific, actionable issues found in the code. These should be problems that exist in the current diff, such as bugs, anti-patterns, or inefficiencies. Do not include suggestions for future improvements here.
            
            3. **improvements**: A list of potential improvements that could be made to the code. These should be specific, actionable, and directly related to the changes in the diff. Format each improvement as a clear, concise suggestion.
            
            4. **code_snippets**: A list of valid Git unified diffs that implement the suggested improvements. Each snippet must follow this format:
            ```
            diff --git a/filepath b/filepath
            index 1111111..2222222 100644
            --- a/filepath
            +++ b/filepath
            @@ -1,5 +1,5 @@
            -old code
            +new code
            ```
            Include 'a/' and 'b/' path prefixes. Ensure the diffs are syntactically correct and can be applied directly.

            **Important Notes**:
            - The `summary` must only describe what was actually changed in the diff. Do not include suggestions for future work or what 'should' be done.
            - The `issues` and `improvements` should be specific to the changes in the diff. Avoid generic or unrelated suggestions.
            - The `code_snippets` must be valid and directly implement the improvements listed.
            
            **Output Format**:
            {
                summary: string,
                issues: string[],
                improvements: string[],
                code_snippets: string[]
            }");
        
        $this->llm->getRolesManager()->addMessage('user', $diff);

        // Notify user that LLM is being called
        $this->output("\nCalling LLM for analysis...", 'cyan');

        // Record start time
        $startTime = microtime(true);

        $response = $this->llm->queryPost();

        // Record end time
        $endTime = microtime(true);

        // Calculate delta time
        $deltaTime = $endTime - $startTime;

        // Notify user that LLM has returned with delta time
        $this->output(sprintf("LLM call completed in %.2f seconds.", $deltaTime), 'cyan');

        if (!$response) {
            throw new Exception("Failed to get LLM response");
        }

        $responseTxt = $this->clean_json_response($response->getLlmResponse());
        $review = json_decode($responseTxt, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response from LLM");
        }

        if (!isset($review['code_snippets']) || !is_array($review['code_snippets'])) {
            throw new Exception("LLM response missing valid code_snippets array");
        }

        foreach ($review['code_snippets'] as $snippet) {
            if (strpos($snippet, 'diff --git') !== 0) {
                throw new Exception("LLM generated invalid diff format");
            }
        }

        return $review;
    }

    private function displayReview(array $review)
    {
        $this->output("\nReview Summary:", 'green');
        $this->output($review['summary'] . "\n");

        if (!empty($review['issues'])) {
            $this->output("Potential Issues:", 'red');
            foreach ($review['issues'] as $issue) {
                $this->output("- $issue", 'red');
            }
        }

        if (!empty($review['improvements'])) {
            $this->output("\nSuggested Improvements (for consideration):", 'cyan');
            foreach ($review['improvements'] as $improvement) {
                $this->output("- $improvement", 'cyan');
            }
        }
    }

    private function handleGitOperations($pr, array $review)
{
    $this->output("\nProcessing improvements...", 'green');
    $branch = $pr['head']['ref'];
    $repoOwner = $_ENV['REPO_OWNER'];
    $repoName = $_ENV['REPO_NAME'];
    
    $files = [];
    foreach ($review['code_snippets'] as $snippet) {
        $filePath = $this->extractFilePathFromDiff($snippet);
        if (!$filePath) {
            $this->output("Skipping invalid diff snippet", 'red');
            continue;
        }

        $currentContent = $this->fetchFileContent($repoOwner, $repoName, $filePath, $branch);
        if ($currentContent === null) {
            $this->output("File $filePath not found, skipping", 'red');
            continue;
        }

        // Show the diff to user with clear context
        $this->output("\n\033[7m Proposed changes for $filePath \033[0m", 'yellow');
        $this->output($snippet);
        
        // Show current file content for comparison
        $this->output("\nCurrent file content:", 'yellow');
        $this->output($currentContent);

        // Apply changes directly in memory
        $patchedContent = $this->applyPatchInMemory($currentContent, $snippet);
        if ($patchedContent !== null) {
            $files[$filePath] = $patchedContent;
            $this->output("Changes prepared for $filePath", 'green');
        }
    }

    if (empty($files)) {
        $this->output("No changes to commit", 'yellow');
        return;
    }

    // Display dynamic improvements based on LLM's suggestions
    if (!empty($review['improvements'])) {
        $this->output("\nThese changes will:", 'yellow');
        foreach ($review['improvements'] as $improvement) {
            $this->output("- $improvement", 'yellow');
        }
    } else {
        $this->output("\nNo specific improvements suggested by the LLM.", 'yellow');
    }

    // Get explicit confirmation for all changes
    $this->confirmAction("Apply all changes and create a new commit on branch '$branch'?");
    
    // Commit message only includes the summary of what was changed
    $commitMessage = $review['summary'] . "\n\nCommit by GPR LLM";

    $this->createCommit($repoOwner, $repoName, $branch, $files, $commitMessage);
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

    private function clean_json_response($response)
    {
        if (preg_match('/```json\s*(.*?)\s*```/s', $response, $matches)) {
            $response = $matches[1];
        }

        $jsonStart = strpos($response, '{');
        $jsonEnd = strrpos($response, '}');

        if ($jsonStart === false || $jsonEnd === false) {
            return '{}';
        }

        $jsonStr = substr($response, $jsonStart, $jsonEnd - $jsonStart + 1);
        $jsonStr = mb_convert_encoding($jsonStr, 'UTF-8', 'UTF-8');
        
        return $jsonStr;
    }
}

(new GitHubPRReviewer())->run();