#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Viceroy\Connections\llamacppOAICompatibleConnection;

class GitHubPRReviewer
{
    private $config;
    private $llm;
    private $prContainsNewFiles = false;
    private $newFilesList = [];

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
            $this->output(sprintf(
                "%d. #%d: %s\n   Created: %s by %s",
                $i + 1,
                $pr['number'],
                $pr['title'],
                date('Y-m-d H:i:s', strtotime($pr['created_at'])),
                $pr['user']['login']
            ), 'yellow');

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
            $this->output("");
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
        return reset($prs);
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

        $rawDiff = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Failed to fetch diff. HTTP Code: {$httpCode}");
        }

        if (preg_match_all('/diff --git a\/(.+?) b\//', $rawDiff, $matches)) {
            foreach ($matches[1] as $file) {
                if (str_contains($rawDiff, "new file mode")) {
                    $this->prContainsNewFiles = true;
                    $this->newFilesList[] = $file;
                }
            }
        }

        return $rawDiff;
    }

    private function analyzeDiff($diff)
    {
        $this->llm = new llamacppOAICompatibleConnection();
        $this->llm->setGuzzleConnectionTimeout(300);

        if (!$this->llm->health()) {
            throw new Exception("LLM endpoint is unavailable");
        }

        $this->llm->getRolesManager()->setSystemMessage("You are a code review specialist. Analyze the DIFF and provide:

<review>
  <summary>Summary of actual changes</summary>
  <issues>
    <issue>Current problem in existing code</issue>
  </issues>
  <changes>
    <change>Improvement to existing code</change>
  </changes>
  <recommendations>
    <recommendation>Future suggestion</recommendation>
  </recommendations>
  <code_snippets>
    <snippet><![CDATA[Valid Git unified diff]]></snippet>
  </code_snippets>
</review>

**STRICT RULES FOR DIFFS:**
1. ❌ NEVER CREATE NEW FILES
2. ❌ NO '/dev/null' IN DIFFS
3. ❌ NO 'new file mode' LINES
4. ✔️ ONLY MODIFY EXISTING FILES
5. ✔️ USE THIS FORMAT:
diff --git a/existing_file.php b/existing_file.php
index 1234567..89abcde 100644
--- a/existing_file.php
+++ b/existing_file.php
@@ -X,Y +X,Y @@
- old_line
+ new_line");

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
            $snippetText = (string)$snippet;
            $this->validateLlmsuggestion($snippetText);
            $review['code_snippets'][] = $snippetText;
        }

        return $review;
    }

    private function validateLlmsuggestion($snippet)
    {
        $forbiddenPatterns = [
            '/new file mode/' => 'LLM suggestions cannot create new files',
            '/deleted file mode/' => 'LLM suggestions cannot delete files',
            '/--- \/dev\/null/' => 'LLM suggestions cannot reference /dev/null',
            '/\+\+\+ b\/[^\s]+/' => 'LLM suggestions must modify existing files'
        ];

        foreach ($forbiddenPatterns as $pattern => $message) {
            if (preg_match($pattern, $snippet)) {
                //throw new Exception("Invalid diff: $message");
            }
        }

        if (!preg_match('/^diff --git a\/.+\s+b\/.+$/m', $snippet)) {
            //throw new Exception("Invalid diff header format");
        }

        if (!preg_match('/@@ -\d+,\d+ \+\d+,\d+ @@/', $snippet)) {
            //throw new Exception("Invalid diff hunk format");
        }

        if (!preg_match('/^-.*/m', $snippet) || !preg_match('/^\+.*/m', $snippet)) {
            //throw new Exception("Diff contains no actual changes");
        }
    }

    private function displayReview(array $review)
    {
        $this->output("\nReview Summary:", 'green');
        $this->output($review['summary'] . "\n");

        if ($this->prContainsNewFiles) {
            $this->output("\nNew Files Detected in PR:", 'magenta');
            $this->output(implode("\n", array_unique($this->newFilesList)), 'magenta');
        }

        if (!empty($review['issues'])) {
            $this->output("\nCritical Issues Found:", 'red');
            foreach ($review['issues'] as $issue) {
                $this->output("- $issue", 'red');
            }
        }

        if (!empty($review['changes'])) {
            $this->output("\nSuggested Code Changes:", 'cyan');
            foreach ($review['changes'] as $change) {
                $this->output("- $change", 'cyan');
            }
        }

        if (!empty($review['recommendations'])) {
            $this->output("\nFuture Recommendations:", 'yellow');
            foreach ($review['recommendations'] as $rec) {
                $this->output("- $rec", 'yellow');
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
            try {
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

                $this->output("\n\033[7m Proposed changes for $filePath \033[0m", 'yellow');
                $this->output($snippet);

                $this->output("\nCurrent file content:", 'yellow');
                $this->output($currentContent);

                $patchedContent = $this->applyPatchInMemory($currentContent, $snippet);
                if ($patchedContent !== null) {
                    $files[$filePath] = $patchedContent;
                    $this->output("Changes prepared for $filePath", 'green');
                }
            } catch (Exception $e) {
                $this->output("\nERROR: " . $e->getMessage(), 'red');
                $this->output("Problematic snippet:", 'yellow');
                $this->output($snippet);
                throw new Exception("Aborting due to invalid diff format");
            }
        }

        if (empty($files)) {
            $this->output("No changes to commit", 'yellow');
            return;
        }

        if (!empty($review['changes'])) {
            $this->output("\nThese changes will implement:", 'yellow');
            foreach ($review['changes'] as $change) {
                $this->output("- $change", 'yellow');
            }
        }

        $this->confirmAction("Apply all changes and create a new commit on branch '$branch'?");

        $commitMessage = $review['summary'] . "\n\nCommit by GPR LLM";
        $this->createCommit($repoOwner, $repoName, $branch, $files, $commitMessage);
    }

    private function applyPatchInMemory($originalContent, $diff)
    {
        $originalLines = explode("\n", $originalContent);
        $diffLines = explode("\n", $diff);
        $result = [];
        $linePointer = 0;

        foreach ($diffLines as $line) {
            if (preg_match('/^@@ -(\d+),?(\d*) \+(\d+),?(\d*) @@/', $line, $matches)) {
                $linePointer = (int)$matches[1] - 1;
                continue;
            }

            if (str_starts_with($line, ' ')) {
                if (isset($originalLines[$linePointer])) {
                    $result[] = $originalLines[$linePointer];
                }
                $linePointer++;
            }

            if (str_starts_with($line, '-')) {
                $linePointer++;
            }

            if (str_starts_with($line, '+')) {
                $result[] = substr($line, 1);
            }
        }

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
            'magenta' => "\033[35m",
            'reset' => "\033[0m",
        ];

        return $colors[$color] . $text . $colors['reset'];
    }

    private function clean_xml_response($response)
    {
        if (preg_match('/```xml\s*(.*?)\s*```/s', $response, $matches)) {
            $response = $matches[1];
        }

        $xmlStart = strpos($response, '<');
        $xmlEnd = strrpos($response, '>');

        if ($xmlStart === false || $xmlEnd === false) {
            return '<review></review>';
        }

        return substr($response, $xmlStart, $xmlEnd - $xmlStart + 1);
    }
}

(new GitHubPRReviewer())->run();
