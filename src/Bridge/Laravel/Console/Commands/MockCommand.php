<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel\Console\Commands;

use ApexDocs\ApexDocs;
use Illuminate\Console\Command;

class MockCommand extends Command
{
    protected $signature = 'apexdocs:mock
        {--host=127.0.0.1 : Bind host}
        {--port=8081      : Bind port}';

    protected $description = 'Start a mock HTTP server that returns example responses from your spec';

    public function handle(ApexDocs $apexDocs): int
    {
        $host = $this->option('host');
        $port = $this->option('port');
        $spec = $apexDocs->generate()->toArray();
        $n = count($spec['paths'] ?? []);

        $this->info("Mock server → <comment>http://{$host}:{$port}</comment> ({$n} endpoints)");
        $this->info("Press Ctrl+C to stop.\n");

        foreach ($spec['paths'] ?? [] as $path => $methods) {
            foreach ($methods as $method => $op) {
                if (is_array($op)) {
                    $this->line(sprintf('  <fg=cyan>%-8s</> %s', strtoupper($method), $path));
                }
            }
        }
        $this->newLine();

        $script = tempnam(sys_get_temp_dir(), 'apexdocs_mock_').'.php';
        file_put_contents($script, $this->buildScript($spec));

        passthru("php -S {$host}:{$port} {$script}");
        @unlink($script);

        return self::SUCCESS;
    }

    private function buildScript(array $spec): string
    {
        $json = json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<PHP
        <?php
        \$spec   = json_decode('{$json}', true);
        \$method = strtolower(\$_SERVER['REQUEST_METHOD']);
        \$path   = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH);

        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('X-Powered-By: ApexDocs Mock');

        if (\$_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');
            http_response_code(204); exit;
        }

        foreach (\$spec['paths'] ?? [] as \$specPath => \$methods) {
            \$pattern = '#^/?' . preg_replace('/\{\\w+\}/', '[^/]+', str_replace('/', '\\/', \$specPath)) . '/?$#';
            if (preg_match(\$pattern, \$path) && isset(\$methods[\$method])) {
                \$op   = \$methods[\$method];
                \$code = (int) (array_key_first(\$op['responses'] ?? ['200' => []]));
                http_response_code(\$code);
                \$resp = \$op['responses'][\$code] ?? [];
                \$schema = \$resp['content']['application/json']['schema'] ?? ['type' => 'object'];
                echo json_encode(apexdocs_example(\$schema, \$spec), JSON_PRETTY_PRINT);
                exit;
            }
        }

        http_response_code(404);
        echo json_encode(['message' => 'Not found in mock spec', 'path' => \$path]);

        function apexdocs_example(array \$s, array \$spec): mixed {
            if (isset(\$s['\$ref'])) {
                \$parts = explode('/', ltrim(\$s['\$ref'], '#/')); \$t = \$spec;
                foreach (\$parts as \$p) { \$t = \$t[\$p] ?? []; }
                return apexdocs_example(\$t, \$spec);
            }
            if (isset(\$s['example'])) return \$s['example'];
            \$t = \$s['type'] ?? 'object';
            if (is_array(\$t)) { \$f = array_values(array_filter(\$t, fn(\$x) => \$x !== 'null')); \$t = \$f[0] ?? 'object'; }
            return match (\$t) {
                'object'  => (object) array_map(fn(\$v) => apexdocs_example(\$v, \$spec), \$s['properties'] ?? ['id' => ['type'=>'integer']]),
                'array'   => [apexdocs_example(\$s['items'] ?? ['type'=>'string'], \$spec)],
                'integer' => 1, 'number' => 1.0, 'boolean' => true, 'null' => null,
                default   => \$s['enum'][0] ?? 'string',
            };
        }
        PHP;
    }
}
