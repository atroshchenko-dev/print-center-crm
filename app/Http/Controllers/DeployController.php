<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DeployController extends Controller
{
    /**
     * GitHub Webhook handler for auto-deploy.
     *
     * Validates SHA-256 signature, decides whether the event is allowed to
     * deploy, then runs deploy.sh in background via nohup.
     *
     * The gate lives in resolveDeployableCommit(): with DEPLOY_REQUIRE_CI on
     * (the default) only a green `workflow_run` deploys, so a red build can no
     * longer reach production.
     */
    public function __invoke(Request $request): JsonResponse
    {
        Log::info('[Deploy] Webhook received', [
            'ip'      => $request->ip(),
            'method'  => $request->method(),
            'headers' => collect($request->headers->all())->only([
                'x-hub-signature-256', 'x-github-event', 'x-github-delivery',
                'content-type', 'user-agent',
            ])->toArray(),
        ]);

        // ─── Validate secret ────────────────────────────
        $secret = config('app.deploy_secret');

        if (! $secret) {
            Log::error('[Deploy] DEPLOY_SECRET not configured in .env');
            return response()->json(['error' => 'DEPLOY_SECRET not configured'], 500);
        }

        // ─── Validate GitHub signature ──────────────────
        $signature = $request->header('X-Hub-Signature-256', '');

        if (! $signature) {
            Log::warning('[Deploy] Missing X-Hub-Signature-256 header');
            return response()->json(['error' => 'Missing signature'], 403);
        }

        $payload  = $request->getContent();
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        if (! hash_equals($expected, $signature)) {
            // Only what arrived, never what was expected: the log is readable by
            // anyone who can read storage/logs, and a prefix of the correct HMAC
            // is a head start on forging one (audit finding L-2).
            Log::warning('[Deploy] Signature mismatch', [
                'received' => substr($signature, 0, 20) . '...',
            ]);
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        Log::info('[Deploy] Signature valid ✓');

        // ─── Decide whether this event may deploy ───────
        $data     = $request->json()->all();
        $event    = $request->header('X-GitHub-Event', 'push');
        $decision = $this->resolveDeployableCommit($event, $data);

        if ($decision['deploy'] === false) {
            Log::info('[Deploy] Ignored', ['event' => $event, 'reason' => $decision['reason']]);
            return response()->json(['status' => 'ignored', 'reason' => $decision['reason']]);
        }

        $commit = $decision['commit'];

        // ─── Run deploy in background ───────────────────
        $deployScript = base_path('deploy.sh');

        // Run deploy.sh as 'deploy' user (www-data has no git credentials)
        // Sudoers rule: www-data ALL=(deploy) NOPASSWD: /usr/bin/bash /var/www/crm-print/deploy.sh
        //
        // Output goes to /dev/null on purpose: deploy.sh pipes its own output
        // through `tee -a storage/logs/deploy.log`. Redirecting here as well
        // pointed tee's stdout back at the same file, so every line landed in
        // the log twice — which is what made a single deploy look like two.
        $cmd = sprintf(
            'nohup sudo -u deploy /usr/bin/bash %s > /dev/null 2>&1 &',
            escapeshellarg($deployScript)
        );

        exec($cmd, $cmdOutput, $cmdCode);
        Log::info('[Deploy] deploy.sh launched', [
            'cmd'       => $cmd,
            'exit_code' => $cmdCode,
            'event'     => $event,
            'commit'    => $commit,
        ]);

        return response()->json([
            'status'  => 'deploying',
            'commit'  => $commit,
            'message' => 'Deploy triggered in background',
        ]);
    }

    /**
     * Decide whether a webhook delivery is allowed to deploy.
     *
     * Two modes, switched by config('app.deploy_require_ci'):
     *
     *  - gated (default): deploy on `workflow_run` for the CI workflow, but
     *    only when it completed successfully on main. Plain `push` events are
     *    acknowledged and dropped, because at push time CI has not run yet —
     *    that parallelism is exactly what let a red build deploy before.
     *  - ungated: the previous behaviour, deploy on every push to main.
     *
     * @param  array<string, mixed>  $data
     * @return array{deploy: bool, reason?: string, commit?: string}
     */
    private function resolveDeployableCommit(string $event, array $data): array
    {
        $requireCi = (bool) config('app.deploy_require_ci', true);

        if ($event === 'ping') {
            return ['deploy' => false, 'reason' => 'ping'];
        }

        if ($event === 'push') {
            if ($requireCi) {
                return ['deploy' => false, 'reason' => 'push ignored, waiting for CI'];
            }

            $ref = $data['ref'] ?? '';

            if ($ref !== 'refs/heads/main') {
                return ['deploy' => false, 'reason' => 'not main branch'];
            }

            return ['deploy' => true, 'commit' => (string) ($data['after'] ?? 'unknown')];
        }

        if ($event !== 'workflow_run') {
            return ['deploy' => false, 'reason' => 'unsupported event: ' . $event];
        }

        if (! $requireCi) {
            return ['deploy' => false, 'reason' => 'CI gate disabled, deploys follow push'];
        }

        if (($data['action'] ?? '') !== 'completed') {
            return ['deploy' => false, 'reason' => 'workflow not finished'];
        }

        /** @var array<string, mixed> $run */
        $run = $data['workflow_run'] ?? [];

        if (($run['name'] ?? '') !== config('app.deploy_ci_workflow', 'CI')) {
            return ['deploy' => false, 'reason' => 'not the CI workflow'];
        }

        if (($run['head_branch'] ?? '') !== 'main') {
            return ['deploy' => false, 'reason' => 'not main branch'];
        }

        if (($run['conclusion'] ?? '') !== 'success') {
            return ['deploy' => false, 'reason' => 'CI conclusion: ' . ($run['conclusion'] ?? 'unknown')];
        }

        return ['deploy' => true, 'commit' => (string) ($run['head_sha'] ?? 'unknown')];
    }

    /**
     * GET /deploy/test — Quick diagnostic endpoint.
     * Returns basic info about whether deploy pipeline can work.
     */
    public function test(): JsonResponse
    {
        $checks = [
            'deploy_secret_set' => ! empty(config('app.deploy_secret')),
            'ci_gate_enabled'   => (bool) config('app.deploy_require_ci', true),
            'ci_workflow'       => config('app.deploy_ci_workflow', 'CI'),
            'deploy_sh_exists'  => file_exists(base_path('deploy.sh')),
            'deploy_sh_exec'    => is_executable(base_path('deploy.sh')),
            'exec_enabled'      => function_exists('exec') && ! in_array('exec', array_map('trim', explode(',', ini_get('disable_functions')))),
            'current_user'      => get_current_user(),
            'php_user'          => exec('whoami 2>&1'),
            'git_branch'        => trim(exec('cd ' . escapeshellarg(base_path()) . ' && git branch --show-current 2>&1')),
            'git_head'          => trim(exec('cd ' . escapeshellarg(base_path()) . ' && git rev-parse --short HEAD 2>&1')),
            'deploy_log_size'   => file_exists(storage_path('logs/deploy.log'))
                                    ? filesize(storage_path('logs/deploy.log'))
                                    : 'not found',
        ];

        return response()->json($checks);
    }
}
