<?php

namespace SilverStripe\TxTranslator;

use GuzzleHttp\Client;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Yaml\Yaml;
use Exception;

class Translator
{
    private int $txMinimumPerc = 10;

    private bool $doTransifexPullAndUpdate = true;

    private bool $doTransifexPush = false;

    private bool $isDevMode = false;

    private string $txSite = '';

    private string $githubToken = '';

    private array $modulePaths = [];

    private array $originalJson = [];

    private array $originalYaml = [];

    public function run()
    {
        $this->checkEnv();
        $this->setModulePaths();
        if ($this->doTransifexPullAndUpdate) {
            $this->log('Updating translations for ' . count($this->modulePaths) . ' module(s)');
            $this->storeJson();
            $this->storeYaml();
            $this->setJsonAndYmlFileTimes();
            $this->transifexPullSource();
            $this->mergeYaml();
            $this->cleanYaml();
            $this->mergeJson();
            $this->collectStrings();
            $this->generateJavascript();
        }
        if ($this->doTransifexPush) {
            $this->transifexPushSource();
        }
        $this->gitCommitPushAndPullRequest();
    }

    private function checkEnv(): void
    {
        $txInstructions = 'Install the new go version of the client https://developers.transifex.com/docs/cli';
        if ($this->exec('which tx') === '') {
            throw new Exception("Could not find tx executable. $txInstructions");
        }
        $help = $this->exec('tx help');
        $help = str_replace(["\n", ' '], '', $help);
        preg_match('#VERSION:([0-9]+\.[0-9]+)#', $help, $matches);
        if (($matches[1] ?? 0) < 1.6) {
            throw new Exception("Your version of tx is too old. $txInstructions");
        }
        if ($this->exec('which wget') === '') {
            throw new Exception('Could not find wget command. Please install it.');
        }
        $this->txSite = getenv('TX_SITE');
        if (!$this->txSite) {
            throw new Exception('TX_SITE environment variable is not defined');
        }
        if (strpos($this->txSite, 'http://') !== 0 && strpos($this->txSite, 'https://') !== 0) {
            $this->txSite = 'http://' . $this->txSite;
        }
        if (!filter_var($this->txSite, FILTER_VALIDATE_URL)) {
            throw new Exception('SITE environment variable is not a valid url');
        }
        $this->githubToken = getenv('GITHUB_API_TOKEN') ?: $this->exec('composer config -g -- github-oauth.github.com');
        if (!$this->githubToken) {
            $message = 'Could not get a valid token from GITHUB_API_TOKEN environment variable or from composer';
            throw new Exception($message);
        }
        // This will be set to true by default if TX_PULL environment variable is not defined
        $this->doTransifexPullAndUpdate = !in_array(strtolower(getenv('TX_PULL')), ['false', '0']);
        // This will be set to false by default if TX_PUSH environment variable is not defined
        $this->doTransifexPush = in_array(strtolower(getenv('TX_PUSH')), ['true', '1']);
        // This will be set to false by default if DEV_MODE environment variable is not defined
        $this->isDevMode = in_array(strtolower(getenv('DEV_MODE')), ['true', '1']);
        $this->log('');
        $txt = $this->isDevMode ? 'ON (changes will not be pushed)' : 'OFF (changes will be pushed!)';
        $this->log("DEV_MODE is $txt");
        $this->log('');
    }

    private function setModulePaths(): void
    {
        $vendorAllowList = [
            'silverstripe',
            'cwp',
            'symbiote',
            'dnadesign'
        ];
        $vendorDir = dirname(dirname(dirname(__DIR__)));
        foreach (scandir($vendorDir) as $vendor) {
            if (!in_array($vendor, $vendorAllowList)) {
                continue;
            }
            foreach (scandir("$vendorDir/$vendor") as $module) {
                $modulePath = "$vendorDir/$vendor/$module";
                if (!file_exists("$modulePath/.tx/config")) {
                    continue;
                }
                $this->modulePaths[] = $modulePath;
            }
        }
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
            foreach (glob($this->getLangDirectory($modulePath) . '/*.yml') as $path) {
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
            $this->log("Pulling sources from transifex for <info>{$name}</info> (min %{$this->txMinimumPerc} delta)");
            $ymlLang = $this->getLangDirectory($modulePath);
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
        foreach ($this->originalYaml as $path => $contentYaml) {
            // If there are any keys in the original yaml that are missing now, add them back in.
            $rawYaml = file_get_contents($path);
            $parsedYaml = Yaml::parse($rawYaml);
            $contentYaml = $this->arrayMergeRecursive($contentYaml, $parsedYaml);
            // Write back to local
            file_put_contents($path, Yaml::dump($contentYaml));
        }
        $this->log('Finished merging ' . count($this->originalYaml) . ' yaml files');
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
            foreach (glob($this->getLangDirectory($modulePath) . "/*.yml") as $sourceFile) {
                $dirty = file_get_contents($sourceFile);
                $sourceData = Yaml::parse($dirty);
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
            $parsedJson = $this->jsonDecode(file_get_contents($path));
            $contentJson = array_merge($contentJson, $parsedJson);
            file_put_contents($path, $this->jsonEncode($contentJson));
        }
        $this->log('Finished merging ' . count($this->originalJson) . ' json files');
    }

    /**
     * Run text collector on the given modules
     */
    private function collectStrings(): void
    {
        $this->log("Running i18nTextCollectorTask");
        $modulesNames = [];
        foreach ($this->modulePaths as $modulePath) {
            $modulesNames[] = $this->getModuleName($modulePath);
        }
        $module = urlencode(implode(',', $modulesNames));
        $site = rtrim($this->txSite, '/');
        $this->exec("wget $site/dev/tasks/i18nTextCollectorTask?flush=all&merge=1&module=$module");
    }

    /**
     * Push source updates to transifex
     */
    private function transifexPushSource(): void
    {
        $this->log('Pushing updated sources to transifex');
        if ($this->isDevMode) {
            $this->log('Not pushing to transifex because DEV_MODE is enabled');
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
            // Get endpoint
            $remote = $this->exec('git config --get remote.origin.url', $modulePath);
            $remote = str_replace('git@github.com:', 'https://github.com/', $remote);
            if (!preg_match('#^https://github.com/([^/]+)/(.+?)$#', $remote, $matches)) {
                throw new Exception("Invalid git remote $remote");
            }
            array_shift($matches);
            list($account, $repo) = $matches;
            $repo = preg_replace('#\.git$#', '', $repo);
            $endpoint = "https://api.github.com/repos/$account/$repo/pulls";

            // Add remote
            if (!in_array('tx-ccs', explode("\n", $this->exec('git remote', $modulePath)))) {
                $this->exec("git remote add tx-ccs git@github.com:creative-commoners/$repo.git", $modulePath);
            }

            // Git add all changes
            $jsPath = $this->getJSLangDirectories($modulePath);
            $langPath = $this->getLangDirectory($modulePath);
            foreach (array_merge((array) $jsPath, (array) $langPath) as $path) {
                if (is_dir($path)) {
                    $this->exec("git add $path/*", $modulePath);
                }
            }
            $this->exec("git add .tx/config", $modulePath);

            // Create new branch
            $currentBranch = $this->exec('git rev-parse --abbrev-ref HEAD', $modulePath);
            $time = time();
            $branch = "pulls/$currentBranch/tx-$time";
            $this->exec("git checkout -b $branch", $modulePath);

            // Commit changes
            $title = 'ENH Update translations';
            $this->exec("git commit -m \"$title\"", $modulePath);

            if ($this->isDevMode) {
                $this->log('Not pushing changes or creating pull-request because DEV_MODE is enabled');
                return;
            }

            // Push changes to creative-commoners
            $this->exec("git push --set-upstream tx-ccs $branch", $modulePath);

            // Create pull-request via github api
            $body = 'Automated translations update generated using silverstripe/tx-translator';
            $client = new Client();
            $response = $client->request('POST', $endpoint, [
                'headers' => [
                    "Accept" =>  "application/vnd.github.v3+json",
                    "Authorization" => "token {$this->githubToken}"
                ],
                'body' => json_encode((object) [
                    "title" => "$title",
                    "body" => $body,
                    "head" => "creative-commoners:$branch",
                    "base" => "$currentBranch"
                ], JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE)
            ]);
            $code = $response->getStatusCode();
            if ($code === 201) {
                $json = json_decode($response->getBody());
                var_dump($response->getBody());
                # $this->pullRequestUrls[] = "https://github.com/$account/$repo/pulls";
            } else {
                $this->log("Pull request failed - status code was $code");
            }
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
        $process->run();
        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
        return trim($process->getOutput());
    }

    private function log(string $message): void
    {
        echo "$message\n";
    }

    /**
     * Gets the module lang dir
     */
    private function getLangDirectory(string $modulePath): string
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
        foreach (preg_split('~\R~u', $content) as $line) {
            if (preg_match('#source_file\s=\s(?<path>\S+)#', $line, $matches)) {
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
        $this->log("Generating javascript locale files");
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
            $sourceContents = $this->jsonEncode($this->jsonDecode(file_get_contents($sourceFile)));
            $locale = preg_replace('/\.js.*$/', '', basename($sourceFile));
            $targetFile = dirname(dirname($sourceFile)) . '/' . $locale . '.js';
            $this->log("Generating file {$targetFile}");
            $targetContents = <<<TEMPLATE
            // This file was generated by silverstripe/cow from %FILE%.
            // See https://github.com/silverstripe/cow for details
            if (typeof(ss) === 'undefined' || typeof(ss.i18n) === 'undefined') {
              if (typeof(console) !== 'undefined') { // eslint-disable-line no-console
                console.error('Class ss.i18n not defined');  // eslint-disable-line no-console
              }
            } else {
              ss.i18n.addDictionary('%LOCALE%', %TRANSLATIONS%);
            }
            TEMPLATE;
            $file = str_replace("$modulePath/", '', $sourceFile);
            $targetContents = str_replace('%TRANSLATIONS%', $sourceContents, $targetContents);
            $targetContents = str_replace('%FILE%', $file, $targetContents);
            $targetContents = str_replace('%LOCALE%', $locale, $targetContents);
            file_put_contents($targetFile, $targetContents);
            $count++;
        }
        return $count;
    }

    private function jsonEncode(array $data): string
    {
        $content = json_encode($data, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE);
        if (json_last_error()) {
            throw new Exception(json_last_error_msg());
        }
        return $content;
    }

    private function jsonDecode(string $str): array
    {
        $json = json_decode($str, true);
        if (json_last_error()) {
            $message = json_last_error_msg();
            throw new Exception("Error json decoding $str: {$message}");
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
}
