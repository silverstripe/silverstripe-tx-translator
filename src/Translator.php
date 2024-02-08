<?php

namespace SilverStripe\TxTranslator;

use GuzzleHttp\Client;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Yaml\Yaml;
use RuntimeException;
use LogicException;
use Symfony\Component\Console\Formatter\OutputFormatter;

class Translator
{
    /**
     * Min % difference required for tx updates
     */
    private int $txMinimumPerc = 10;

    /**
     * Flag whether we should run `tx pull`, i18nTextCollectorTask and js/json update`
     */
    private bool $doTransifexPullAndUpdate = true;

    private bool $doTransifexPush = false;

    private bool $doCollectStrings = false;

    private bool $isDevMode = false;

    private string $txSite = '';

    private string $githubToken = '';

    private array $modulePaths = [];

    /** Original json content for ALL modules */
    private array $originalJson = [];

    /** Original yaml content for ALL modules */
    private array $originalYaml = [];

    private array $pullRequestUrls = [];

    private bool $versboseLogging = false;

    private ?OutputFormatter $outputFormatter = null;

    public function run()
    {
        $this->outputFormatter = new OutputFormatter(true);
        $this->checkEnv();
        $this->setModulePaths();
        if ($this->doTransifexPullAndUpdate) {
            $this->log('Updating translations for ' . count($this->modulePaths) . ' module(s)');
            $this->storeJson();
            $this->storeYaml();
            $this->setJsonAndYmlFileTimes();
            $this->transifexPullSource();
            $this->mergeYaml();
            $this->removeEnglishStringsFromYamlTranslations();
            $this->cleanYaml();
            $this->mergeJson();
            $this->removeEnglishStringsFromJsonTranslations();
        }
        if ($this->doCollectStrings) {
            $this->collectStrings();
        }
        if ($this->doTransifexPullAndUpdate) {
            $this->generateJavascript();
        }
        if ($this->doTransifexPush) {
            $this->transifexPushSource();
        }
        $this->gitCommitPushAndPullRequest();
        $this->outputPullRequestUrls();
    }

    private function checkEnv(): void
    {
        $txInstructions = 'Install the new go version of the client https://developers.transifex.com/docs/cli';
        try {
            $this->exec('which tx');
        } catch (ProcessFailedException $e) {
            throw new RuntimeException("Could not find tx executable. $txInstructions");
        }
        $help = $this->exec('tx help');
        $help = str_replace(["\n", ' '], '', $help);
        preg_match('#VERSION:([0-9]+\.[0-9]+)#', $help, $matches);
        if (($matches[1] ?? 0) < 1.6) {
            throw new RuntimeException("Your version of tx is too old. $txInstructions");
        }
        try {
            $this->exec('which wget');
        } catch (ProcessFailedException $e) {
            throw new RuntimeException('Could not find wget command. Please install it.');
        }
        $this->githubToken = getenv('TX_GITHUB_API_TOKEN');
        if (!$this->githubToken) {
            $message = implode(' ', [
                'Could not get a valid token from TX_GITHUB_API_TOKEN environment variable.',
                'Create a new token with the public_repo checkbox ticked that will allow you to create pull-requests.'
            ]);
            throw new RuntimeException($message);
        }
        // This will be set to true by default if TX_PULL environment variable is not defined
        $this->doTransifexPullAndUpdate = !in_array(strtolower(getenv('TX_PULL')), ['off', 'false', '0']);
        // This will be set to false by default if TX_PUSH environment variable is not defined
        $this->doTransifexPush = in_array(strtolower(getenv('TX_PUSH')), ['on', 'true', '1']);
        if (!$this->doTransifexPullAndUpdate && !$this->doTransifexPush) {
            throw new RuntimeException('Either TX_PULL or TX_PUSH must be set to true');
        }
        // This will be set to false by default if TX_COLLECT environment variable is not defined
        $this->doCollectStrings = in_array(strtolower(getenv('TX_COLLECT')), ['on', 'true', '1']);
        if ($this->doCollectStrings) {
            $this->txSite = getenv('TX_SITE');
            if (!$this->txSite) {
                throw new RuntimeException('TX_SITE environment variable is not defined');
            }
            if (strpos($this->txSite, 'http://') !== 0 && strpos($this->txSite, 'https://') !== 0) {
                $this->txSite = 'http://' . $this->txSite;
            }
            if (!parse_url($this->txSite, PHP_URL_HOST)) {
                throw new RuntimeException('SITE environment variable is not a valid url');
            }
        }
        // This will be set to false by default if TX_DEV_MODE environment variable is not defined
        $this->isDevMode = in_array(strtolower(getenv('TX_DEV_MODE')), ['on', 'true', '1']);
        // This will be set to false by default if TX_VERBOSE_LOGGING environment variable is not defined
        $this->versboseLogging = in_array(strtolower(getenv('TX_VERBOSE_LOGGING')), ['on', 'true', '1']);
        $txt = $this->isDevMode ? 'ON (changes will not be pushed)' : 'OFF (changes will be pushed!)';
        $this->log("<question>TX_DEV_MODE is $txt</question>");
    }

    private function scanDir(string $dir): array
    {
        return array_diff(scanDir($dir), ['.', '..']);
    }

    private function getFrameworkMajor(): string
    {
        $output = $this->exec('composer show silverstripe/framework | grep versions');
        if (!preg_match('#^versions : \* ([4-9])#', $output, $matches)) {
            throw new LogicException('Could not work out major version of framework');
        }
        return $matches[1];
    }

    private function setModulePaths(): void
    {
        $client = new Client();
        $cmsMajor = $this->getFrameworkMajor();
        $url = "https://raw.githubusercontent.com/silverstripe/supported-modules/$cmsMajor/modules.json";
        $body = (string) $client->request('GET', $url)->getBody();
        $supportedVendors = [];
        $supportedModules = [];
        foreach ($this->jsonDecode($body) as $data) {
            $supportedModules[] = $data['composer'];
            $supportedVendors[] = explode('/', $data['composer'])[0];
        }
        $vendorDir = dirname(dirname(dirname(__DIR__)));
        foreach ($this->scandir($vendorDir) as $vendor) {
            if (!in_array($vendor, $supportedVendors)) {
                continue;
            }
            foreach ($this->scandir("$vendorDir/$vendor") as $module) {
                $modulePath = "$vendorDir/$vendor/$module";
                if (!in_array("$vendor/$module", $supportedModules)) {
                    continue;
                }
                if (!file_exists("$modulePath/.tx/config")) {
                    continue;
                }
                // See if the current "branch" is actually a tag that's been checked out,
                // rather than a minor or next minor branch.
                // If so, try to git checkout the what the minor branch for the tag should be.
                // Read all branches rather than just using `git rev-parse --abbrev-ref HEAD` so that
                // tags aren't just reported as "HEAD", instead they are "(HEAD detached at 3.0.2)"
                $branches = $this->exec('git branch', $modulePath);
                // the current branch will be prefixed with a *, extract what the branch is with a regex
                preg_match("#(^|\n)\* (.+?)(\n|$)#", $branches, $matches);
                $branch = $matches[2] ?? 'invalid';
                $cleanBranch = $this->getCleanBranch($branch);
                if ($cleanBranch !== $branch) {
                    $this->exec("git checkout $cleanBranch", $modulePath);
                }
                $branch = $this->exec('git rev-parse --abbrev-ref HEAD', $modulePath);
                if (!is_numeric($branch)) {
                    throw new RuntimeException("Branch $branch in $modulePath is not a minor or next-minor branch");
                }
                $this->modulePaths[] = $modulePath;
            }
        }
    }

    /**
     * A tag will show up as "(HEAD detached at 3.0.2)" - return this as 3.0
     */
    private function getCleanBranch(string $branch): string
    {
        if (preg_match('#^\(HEAD detached at ([0-9]+\.[0-9]+)\.[0-9]+#', $branch, $matches)) {
            return $matches[1];
        }
        return $branch;
    }

    /**
     * Backup local json files prior to replacing local copies with transifex
     */
    private function storeJson(): void
    {
        $this->log('Backing up local json files');
        // Backup files prior to replacing local copies with transifex
        $this->originalJson = [];
        foreach ($this->modulePaths as $modulePath) {
            $jsPath = $this->getJSLangDirectories($modulePath);
            foreach ((array) $jsPath as $langDir) {
                foreach (glob($langDir . '/src/*.json') as $path) {
                    $str = file_get_contents($path);
                    $this->originalJson[$path] = $this->jsonDecode($str);
                }
            }
        }
        $this->log('Finished backing up ' . count($this->originalJson) . ' json files');
    }

    /**
     * Backup local yaml files in memory prior to replacing local copies with transifex
     */
    private function storeYaml(): void
    {
        $this->log('Backing up local yaml files');
        $this->originalYaml = [];
        foreach ($this->modulePaths as $modulePath) {
            foreach (glob($this->getYmlLangDirectory($modulePath) . '/*.yml') as $path) {
                $rawYaml = file_get_contents($path);
                $this->originalYaml[$path] = Yaml::parse($rawYaml);
            }
        }
        $this->log('Finished backing up ' . count($this->originalYaml) . ' yaml files');
    }

    /**
     * Set mtime to a year ago so that transifex will see these as obsolete
     */
    private function setJsonAndYmlFileTimes()
    {
        $date = date('YmdHi.s', strtotime('-1 year'));
        foreach ($this->modulePaths as $modulePath) {
            $name = $this->getModuleName($modulePath);
            $this->log("Setting file mtime to a past date for <info>{$name}</info>");
            $ymlLang = $this->getYmlLangDirectory($modulePath);
            if ($ymlLang) {
                $this->exec("find $ymlLang -type f \( -name \"*.yml\" \) -exec touch -t $date {} \;");
            }
            foreach ($this->getJSLangDirectories($modulePath) as $jsLangDir) {
                $this->exec("find $jsLangDir -type f \( -name \"*.json*\" \) -exec touch -t $date {} \;");
            }
        }
    }

    /**
     * Update sources from transifex
     */
    private function transifexPullSource()
    {
        foreach ($this->modulePaths as $modulePath) {
            $this->log("Pulling translations for $modulePath");
            // ensure .tx/config is up to date
            $contents = file_get_contents("$modulePath/.tx/config");
            if (strpos($contents, '[o:') === false) {
                $this->exec('tx migrate', $modulePath);
                // delete .bak files created as part of tx migrate
                foreach (scandir("$modulePath/.tx") as $filename) {
                    if (pathinfo($filename, PATHINFO_EXTENSION) !== 'bak') {
                        continue;
                    }
                    $this->log("Deleting $modulePath/.tx/$filename");
                    unlink("$modulePath/.tx/$filename");
                }
            }
            // pull from transifex
            $this->exec("tx pull -a -s -t -f --minimum-perc={$this->txMinimumPerc}", $modulePath);
        }
    }

    /**
     * Merge any missing keys from old yaml content into yaml files
     */
    private function mergeYaml(): void
    {
        $this->log('Merging local yaml files');
        // $this->originalYaml at this point is yaml prior to adding in transifex yaml
        foreach ($this->originalYaml as $path => $contentYaml) {
            // If there are any keys in the original yaml that are missing now, add them back in.
            if (file_exists($path)) {
                $rawYaml = file_get_contents($path);
                // $parsedYaml at this point is transifex yaml
                $parsedYaml = Yaml::parse($rawYaml);
                $this->removeBlankStrings($parsedYaml);
                // transifex yaml has precedence over original yaml
                $contentYaml = $this->arrayMergeRecursive($contentYaml, $parsedYaml);
                $this->recursiveKeySort($contentYaml);
            }
            // Write back to local
            file_put_contents($path, Yaml::dump($contentYaml));
        }
        $this->log('Finished merging ' . count($this->originalYaml) . ' yaml files');
    }

    /**
     * Transifex may add english strings to translation yaml files, remove them
     */
    private function removeEnglishStringsFromYamlTranslations(): void
    {
        $this->log('Removing english from yml translation files');
        $count = 0;
        foreach (array_keys($this->originalYaml) as $path) {
            if (!file_exists($path)) {
                continue;
            }
            $enPath = preg_replace('#/[^/\.]+\.yml#', '/en.yml', $path);
            $enYaml = Yaml::parse(file_get_contents($enPath));
            if ($enPath === $path) {
                continue;
            }
            // Remove any keys where the value is the same as the source english value
            $contentYaml = Yaml::parse(file_get_contents($path));
            foreach (array_keys($contentYaml) as $countryCode) {
                foreach (array_keys($contentYaml[$countryCode] ?? []) as $className) {
                    foreach (array_keys($contentYaml[$countryCode][$className]) as $key) {
                        $value = $contentYaml[$countryCode][$className][$key] ?? null;
                        $enValue = $enYaml['en'][$className][$key] ?? null;
                        if ($value === $enValue) {
                            unset($contentYaml[$countryCode][$className][$key]);
                            $count++;
                        }
                        // Remove any className nodes that have had all their keys removed
                        if (
                            isset($contentYaml[$countryCode][$className])
                            && empty($contentYaml[$countryCode][$className])
                        ) {
                            unset($contentYaml[$countryCode][$className]);
                        }
                    }
                }
            }
            // Write back to local
            file_put_contents($path, Yaml::dump($contentYaml));
        }
        $this->log("Removed $count english string(s) from yml translation files");
    }

    private $blankEnStrings;

    private function removeBlankStrings(array &$sourceData)
    {
        $this->blankEnStrings = [];
        foreach ($sourceData as $lang => &$data) {
            if ($lang !== 'en') {
                continue;
            }
            $this->recursiveRecordBlankStrings('', $data);
        }
        foreach ($sourceData as $lang => &$data) {
            if ($lang === 'en') {
                continue;
            }
            if (is_array($data)) {
                $this->recursiveRemoveBlankStrings('', $data);
            }
        }
    }

    private function recursiveRecordBlankStrings(string $parentKey, array &$data)
    {
        foreach ($data as $key => &$val) {
            if (is_array($val)) {
                $this->recursiveRecordBlankStrings($parentKey . '-' . $key, $val);
            }
            if (is_string($val) && $this->isEmpty($val)) {
                $this->blankEnStrings[$parentKey . '-' . $key] = true;
            }
        }
    }

    private function recursiveRemoveBlankStrings(string $parentKey, array &$data)
    {
        foreach ($data as $key => &$val) {
            if (is_array($val)) {
                $this->recursiveRemoveBlankStrings($parentKey . '-' . $key, $val);
            }
            if ($this->isEmpty($val) && !array_key_exists($parentKey . '-' . $key, $this->blankEnStrings)) {
                unset($data[$key]);
            }
        }
    }

    private function isEmpty($val)
    {
        return empty($val) && $val !== '0';
    }

    /**
     * Tidy yaml files using symfony yaml
     */
    private function cleanYaml()
    {
        foreach ($this->modulePaths as $modulePath) {
            $name = $this->getModuleName($modulePath);
            $this->log("Cleaning YAML sources for <info>{$name}</info>");
            $num = 0;
            foreach (glob($this->getYmlLangDirectory($modulePath) . '/*.yml') as $sourceFile) {
                $dirty = file_get_contents($sourceFile);
                $sourceData = Yaml::parse($dirty);
                $this->removeBlankStrings($sourceData);
                $this->recursiveKeySort($sourceData);
                $cleaned = Yaml::dump($sourceData, 9999, 2);
                if ($dirty !== $cleaned) {
                    $num++;
                    file_put_contents($sourceFile, $cleaned);
                }
            }
            $this->log("<info>{$num}</info> yml files cleaned");
        }
    }

    /**
     * Merge any missing keys from old json content into json files
     */
    private function mergeJson(): void
    {
        $this->log('Merging local json files');
        foreach ($this->originalJson as $path => $contentJson) {
            // If there are any keys in the original json that are missing now, add them back in.
            if (file_exists($path)) {
                $parsedJson = $this->jsonDecode(file_get_contents($path));
                $contentJson = array_merge($contentJson, $parsedJson);
                $this->recursiveKeySort($contentJson);
            }
            // Write back to local
            file_put_contents($path, $this->jsonEncode($contentJson));
        }
        $this->log('Finished merging ' . count($this->originalJson) . ' json files');
    }

    /**
     * Transifex may add english strings to translation json files, remove them
     */
    private function removeEnglishStringsFromJsonTranslations(): void
    {
        $this->log('Removing english from json translation files');
        $count = 0;
        foreach (array_keys($this->originalJson) as $path) {
            if (!file_exists($path)) {
                continue;
            }
            $enPath = preg_replace('#/[^/\.]+\.json$#', '/en.json', $path);
            $enJson = $this->jsonDecode(file_get_contents($enPath));
            if ($enPath === $path) {
                continue;
            }
            // Remove any keys where the value is the same as the source english value
            $contentJson = $this->jsonDecode(file_get_contents($path));
            foreach (array_keys($contentJson) as $key) {
                if (array_key_exists($key, $enJson) && $enJson[$key] === $contentJson[$key]) {
                    unset($contentJson[$key]);
                    $count++;
                }
            }
            // Write back to local
            file_put_contents($path, $this->jsonEncode($contentJson));
        }
        $this->log("Removed $count english string(s) from json translation files");
    }

    /**
     * Run text collector on the given modules
     */
    private function collectStrings(): void
    {
        $this->log('Running i18nTextCollectorTask');
        $modulesNames = [];
        foreach ($this->modulePaths as $modulePath) {
            $modulesNames[] = $this->getModuleName($modulePath);
        }
        $module = urlencode(implode(',', $modulesNames));
        $site = rtrim($this->txSite, '/');
        $this->exec("wget --content-on-error $site/dev/tasks/i18nTextCollectorTask?module=$module");
    }

    /**
     * Push source updates to transifex
     */
    private function transifexPushSource(): void
    {
        $this->log('Pushing updated sources to transifex');
        if ($this->isDevMode) {
            $this->log('Not pushing to transifex because TX_DEV_MODE is enabled');
            return;
        }
        foreach ($this->modulePaths as $modulePath) {
            $this->exec('tx push -s', $modulePath);
        }
    }

    /**
     * Commit changes for all modules
     */
    private function gitCommitPushAndPullRequest(): void
    {
        $this->log('Committing translations to git');
        foreach ($this->modulePaths as $modulePath) {
            $this->log("Committing translations for $modulePath");
            // Get endpoint
            $remote = $this->exec('git config --get remote.origin.url', $modulePath);
            if (!preg_match('#^(https://github\.com/|git@github\.com:)([^/]+)/(.+?)(\.git)?$#', $remote, $matches)) {
                throw new RuntimeException("Invalid git remote $remote");
            }
            $account = $matches[2];
            $repo = $matches[3];
            $endpoint = "https://api.github.com/repos/$account/$repo/pulls";

            // Add remote
            if (!in_array('tx-ccs', explode("\n", $this->exec('git remote', $modulePath)))) {
                $this->exec("git remote add tx-ccs git@github.com:creative-commoners/$repo.git", $modulePath);
            }

            // Git add all changes
            $jsPath = $this->getJSLangDirectories($modulePath);
            $langPath = $this->getYmlLangDirectory($modulePath);
            foreach (array_merge((array) $jsPath, (array) $langPath) as $path) {
                if (is_dir($path)) {
                    $this->exec("git add $path/*", $modulePath);
                }
            }
            $this->exec("git add .tx/config", $modulePath);

            // Check if there's anything to commit
            $status = $this->exec('git status', $modulePath);
            if (strpos($status, 'nothing to commit') !== false) {
                $this->log('Nothing to commit, continuing');
                continue;
            }

            // Create new branch
            $currentBranch = $this->exec('git rev-parse --abbrev-ref HEAD', $modulePath);
            $time = time();
            $branch = "pulls/$currentBranch/tx-$time";
            $this->exec("git checkout -b $branch", $modulePath);

            // Commit changes
            $title = 'TLN Update translations';
            $this->exec("git commit -m \"$title\"", $modulePath);

            if ($this->isDevMode) {
                $this->log(implode(' ', [
                    'Not pushing changes or creating pull-request because TX_DEV_MODE is enabled.',
                    'A new branch was created.'
                ]));
                continue;
            }

            // Push changes to creative-commoners
            $this->exec("git push --set-upstream tx-ccs $branch", $modulePath);

            // Ensure secondary-rate limit is not exceeded
            $seconds = 10;
            $this->log("Sleeping $seconds seconds so that secondary rate-limit is not exceeded");
            sleep($seconds);

            // Create pull-request via github api
            // https://docs.github.com/en/rest/pulls/pulls#create-a-pull-request
            $body = implode(' ', [
                'Automated translations update generated using',
                '[silverstripe/tx-translator](https://github.com/silverstripe/silverstripe-tx-translator)'
            ]);
            $client = new Client();
            $response = $client->request('POST', $endpoint, [
                'headers' => [
                    "Accept" => "application/vnd.github.v3+json",
                    "Authorization" => "token {$this->githubToken}"
                ],
                'body' => $this->jsonEncode([
                    "title" => $title,
                    "body" => $body,
                    "head" => "creative-commoners:$branch",
                    "base" => $currentBranch
                ])
            ]);
            $code = $response->getStatusCode();
            $responseBody = (string) $response->getBody();
            if ($code === 201) {
                $pullRequestUrl = json_decode($responseBody)->html_url;
                $this->log("Pull request was succesfully created at $pullRequestUrl");
                $this->pullRequestUrls[] = $pullRequestUrl;
            } else {
                $this->log($responseBody);
                throw new RuntimeException("Pull request failed - status code was $code");
            }
        }
    }

    private function outputPullRequestUrls(): void
    {
        $this->log('<info>The following pull-requests were created:</info>');
        foreach ($this->pullRequestUrls as $pullRequestUrl) {
            $this->log("- $pullRequestUrl");
        }
    }

    private function getModuleName(string $modulePath): string
    {
        return json_decode(file_get_contents("$modulePath/composer.json"))->name;
    }

    private function exec(string $command, ?string $cwd = null): string
    {
        $this->log("Running $command");
        $process = Process::fromShellCommandline($command, $cwd);
        // set a timeout of 5 minutes - tx pull operations may take a long time
        $process->setTimeout(300);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
        $output = trim($process->getOutput());
        $this->log($output, true);
        return $output;
    }

    private function log(string $message, bool $isVerbose = false): void
    {
        if ($isVerbose && !$this->versboseLogging) {
            return;
        }
        echo $this->outputFormatter->format($message) . "\n";
    }

    /**
     * Gets the module lang dir
     */
    private function getYmlLangDirectory(string $modulePath): ?string
    {
        $sources = $this->getTransifexSources($modulePath);
        foreach ($sources as $source) {
            if (preg_match('#^(?<dir>.+)\\/(?<file>[^\\/]+)\\.yml$#', $source, $matches)) {
                return $modulePath . '/' . $matches['dir'];
            }
        }
        return null;
    }

    /**
     * Gets the directories of the JS lang folder.
     */
    private function getJSLangDirectories(string $modulePath): array
    {
        $sources = $this->getTransifexSources($modulePath);
        $dirs = [];
        foreach ($sources as $source) {
            // Strip out /src/ dir and trailing file.js
            if (preg_match('#^(?<dir>.+)\\/src\\/(?<file>[^\\/]+)\\.js(on)?$#', $source, $matches)) {
                $dirs[] = $modulePath . '/' . $matches['dir'];
            }
        }
        return $dirs;
    }

    /**
     * Get list of transifex source files. E.g. lang/en.yml
     */
    private function getTransifexSources(string $modulePath): array
    {
        $path = "$modulePath/.tx/config";
        $content = file_get_contents($path);
        $sources = [];
        foreach (preg_split('#\R#u', $content) as $line) {
            if (preg_match('#source_file\s+=\s+(?<path>\S+)#', $line, $matches)) {
                $sources[] = $matches['path'];
            }
        }
        return $sources;
    }

    /**
     * Generate javascript for all modules
     */
    private function generateJavascript(): void
    {
        $this->log('Generating javascript locale files');
        // Check which paths in each module require processing
        $count = 0;
        foreach ($this->modulePaths as $modulePath) {
            $jsPaths = $this->getJSLangDirectories($modulePath);
            foreach ((array)$jsPaths as $jsPath) {
                $count += $this->generateJavascriptInDirectory($modulePath, $jsPath);
            }
        }
        $this->log("Finished generating {$count} files");
    }

    /**
     * Process all javascript in a given path
     */
    private function generateJavascriptInDirectory(string $modulePath, string $jsPath): int
    {
        $count = 0;
        foreach (glob("{$jsPath}/src/*.js*") as $sourceFile) {
            // re-encode contents to ensure they're consistently formatted
            $sourceContentsDecoded = $this->jsonDecode(file_get_contents($sourceFile));
            $this->recursiveKeySort($sourceContentsDecoded);
            $sourceContentsEncoded = $this->jsonEncode($sourceContentsDecoded);
            $locale = preg_replace('/\.js.*$/', '', basename($sourceFile));
            $targetFile = dirname(dirname($sourceFile)) . '/' . $locale . '.js';
            $this->log("Generating file {$targetFile}", true);
            $file = str_replace("$modulePath/", '', $sourceFile);
            $targetContents = <<<EOT
            // This file was generated by silverstripe/tx-translator from $file.
            // See https://github.com/silverstripe/silverstripe-tx-translator for details
            if (typeof(ss) === 'undefined' || typeof(ss.i18n) === 'undefined') {
              if (typeof(console) !== 'undefined') { // eslint-disable-line no-console
                console.error('Class ss.i18n not defined');  // eslint-disable-line no-console
              }
            } else {
              ss.i18n.addDictionary('$locale', $sourceContentsEncoded);
            }
            EOT;
            file_put_contents($targetFile, $targetContents);
            $count++;
        }
        return $count;
    }

    private function jsonEncode(array $data): string
    {
        $content = json_encode($data, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE);
        if (json_last_error()) {
            throw new LogicException(json_last_error_msg());
        }
        return $content;
    }

    private function jsonDecode(string $str): array
    {
        $json = json_decode($str, true);
        if (json_last_error()) {
            $message = json_last_error_msg();
            throw new LogicException("Error json decoding $str: {$message}");
        }
        return $json;
    }

    /**
     * Recursively merges two arrays.
     *
     * Behaves similar to array_merge_recursive(), however it only merges
     * values when both are arrays rather than creating a new array with
     * both values, as the PHP version does.
     */
    private function arrayMergeRecursive(array $array1, array $array2): array
    {
        foreach ($array2 as $key => $value) {
            if (is_array($value) && array_key_exists($key, $array1) && is_array($array1[$key])) {
                $array1[$key] = $this->arrayMergeRecursive($array1[$key], $value);
            } else {
                $array1[$key] = $value;
            }
        }
        return $array1;
    }

    private function recursiveKeySort(array &$arr): void
    {
        ksort($arr);
        foreach (array_keys($arr) as $key) {
            if (is_array($arr[$key])) {
                $this->recursiveKeySort($arr[$key]);
            }
        }
    }
}
