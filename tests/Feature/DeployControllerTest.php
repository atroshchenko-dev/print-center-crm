<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DeployControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure deploy_secret is set for tests that check signature validation.
        // Without this, the controller returns 500 before reaching signature checks.
        config(['app.deploy_secret' => 'test-webhook-secret']);
    }

    public function test_rejects_request_without_signature(): void
    {
        $response = $this->postJson('deploy/webhook', ['ref' => 'refs/heads/main']);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'Missing signature']);
    }

    public function test_rejects_request_with_invalid_signature(): void
    {
        $response = $this->postJson('deploy/webhook', ['ref' => 'refs/heads/main'], [
            'X-Hub-Signature-256' => 'sha256=invalidsignature',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'Invalid signature']);
    }

    public function test_ignores_non_main_branch(): void
    {
        config(['app.deploy_require_ci' => false]);

        $response = $this->webhook('push', ['ref' => 'refs/heads/develop', 'after' => 'abc123']);

        $response->assertOk();
        $response->assertJson(['status' => 'ignored', 'reason' => 'not main branch']);
    }

    // ─── CI gate ──────────────────────────────────────────────
    //
    // Before the gate, the push webhook and the CI run raced each other, so a
    // build that went red still deployed. These tests pin the rule that only a
    // finished, successful CI run on main may launch deploy.sh.

    public function test_push_to_main_does_not_deploy_while_ci_gate_is_on(): void
    {
        $response = $this->webhook('push', ['ref' => 'refs/heads/main', 'after' => 'abc123']);

        $response->assertOk();
        $response->assertJson(['status' => 'ignored', 'reason' => 'push ignored, waiting for CI']);
    }

    public function test_failed_ci_run_does_not_deploy(): void
    {
        $response = $this->webhook('workflow_run', $this->workflowRun(['conclusion' => 'failure']));

        $response->assertOk();
        $response->assertJson(['status' => 'ignored', 'reason' => 'CI conclusion: failure']);
    }

    public function test_cancelled_ci_run_does_not_deploy(): void
    {
        $response = $this->webhook('workflow_run', $this->workflowRun(['conclusion' => 'cancelled']));

        $response->assertOk();
        $response->assertJson(['status' => 'ignored', 'reason' => 'CI conclusion: cancelled']);
    }

    public function test_ci_run_still_in_flight_does_not_deploy(): void
    {
        $payload = $this->workflowRun(['conclusion' => null]);
        $payload['action'] = 'in_progress';

        $response = $this->webhook('workflow_run', $payload);

        $response->assertOk();
        $response->assertJson(['status' => 'ignored', 'reason' => 'workflow not finished']);
    }

    public function test_successful_ci_run_on_another_branch_does_not_deploy(): void
    {
        $response = $this->webhook('workflow_run', $this->workflowRun(['head_branch' => 'feature/x']));

        $response->assertOk();
        $response->assertJson(['status' => 'ignored', 'reason' => 'not main branch']);
    }

    public function test_success_of_an_unrelated_workflow_does_not_deploy(): void
    {
        $response = $this->webhook('workflow_run', $this->workflowRun(['name' => 'Nightly']));

        $response->assertOk();
        $response->assertJson(['status' => 'ignored', 'reason' => 'not the CI workflow']);
    }

    /**
     * The only path that launches deploy.sh. It really does reach exec(), but
     * the command is `sudo -u deploy … &` with output discarded: off the VPS
     * there is no such user, so the shell backgrounds it and it dies at once.
     */
    public function test_green_ci_run_on_main_deploys_the_tested_commit(): void
    {
        $response = $this->webhook('workflow_run', $this->workflowRun(['head_sha' => 'deadbeef']));

        $response->assertOk();
        $response->assertJson(['status' => 'deploying', 'commit' => 'deadbeef']);
    }

    public function test_workflow_run_is_ignored_when_the_gate_is_disabled(): void
    {
        config(['app.deploy_require_ci' => false]);

        $response = $this->webhook('workflow_run', $this->workflowRun());

        $response->assertOk();
        $response->assertJson(['status' => 'ignored', 'reason' => 'CI gate disabled, deploys follow push']);
    }

    public function test_ping_event_is_acknowledged_without_deploying(): void
    {
        $response = $this->webhook('ping', ['zen' => 'Non-blocking is better than blocking.']);

        $response->assertOk();
        $response->assertJson(['status' => 'ignored', 'reason' => 'ping']);
    }

    /**
     * A completed, successful CI run on main — the only shape that deploys.
     *
     * @param  array<string, mixed>  $overrides  merged into the workflow_run body
     * @return array<string, mixed>
     */
    private function workflowRun(array $overrides = []): array
    {
        return [
            'action'       => 'completed',
            'workflow_run' => array_merge([
                'name'        => 'CI',
                'head_branch' => 'main',
                'head_sha'    => 'abc123',
                'conclusion'  => 'success',
                'status'      => 'completed',
            ], $overrides),
        ];
    }

    /**
     * POST a correctly signed webhook delivery of the given GitHub event.
     *
     * @param  array<string, mixed>  $payload
     */
    private function webhook(string $event, array $payload): TestResponse
    {
        $body = json_encode($payload);
        $sig = 'sha256='.hash_hmac('sha256', $body, (string) config('app.deploy_secret'));

        return $this->call('POST', 'deploy/webhook', [], [], [], [
            'HTTP_X-Hub-Signature-256' => $sig,
            'HTTP_X-GitHub-Event'      => $event,
            'CONTENT_TYPE'             => 'application/json',
        ], $body);
    }

    public function test_returns_500_when_secret_not_configured(): void
    {
        config(['app.deploy_secret' => null]);

        $response = $this->postJson('deploy/webhook', ['ref' => 'refs/heads/main'], [
            'X-Hub-Signature-256' => 'sha256=anything',
        ]);

        $response->assertStatus(500);
        $response->assertJson(['error' => 'DEPLOY_SECRET not configured']);
    }

    public function test_endpoint_requires_authentication(): void
    {
        $response = $this->get('deploy/test');

        $response->assertStatus(302); // redirect to login
    }

    public function test_endpoint_works_for_authenticated_user(): void
    {
        $user = User::factory()->create([
            'role'        => UserRole::Admin,
            'permissions' => User::permissionKeys(),
        ]);

        $response = $this->actingAs($user)->getJson('deploy/test');

        $response->assertOk();
        $response->assertJsonStructure([
            'deploy_secret_set',
            'deploy_sh_exists',
            'exec_enabled',
            'current_user',
            'php_user',
        ]);
    }

    /**
     * Rollback must rebuild what the failed deploy destroyed (finding N-2,
     * fifth pass). `npm run build` empties public/build before writing, so
     * a build that dies — OOM on the VPS, full disk — leaves no manifest;
     * rollback() restored code and vendor, told Telegram «✅ Rolled back»,
     * and every page answered 500 until someone rebuilt by hand. A source
     * assertion, because deploy.sh runs on the server and nowhere else.
     */
    public function test_the_rollback_rebuilds_the_frontend_it_lost(): void
    {
        $script = (string) file_get_contents(base_path('deploy.sh'));

        $this->assertSame(
            1,
            preg_match('/rollback\(\)\s*\{(.*?)\n\}/s', $script, $body),
            'deploy.sh no longer has a rollback() function in the shape this test reads.',
        );

        $this->assertStringContainsString(
            'npm run build',
            $body[1],
            'rollback() restores code and vendor but not the frontend bundle the failed build emptied.',
        );
    }
}
